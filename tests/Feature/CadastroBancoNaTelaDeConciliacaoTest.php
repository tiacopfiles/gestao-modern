<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cadastrar a conta bancária pela própria tela de abertura da conciliação.
 *
 * O que motivou: das 42 empresas em produção, 3 tinham conta bancária
 * cadastrada. Nas outras 39 o campo "Banco" aparecia desabilitado com
 * "nenhum banco cadastrado" e não havia saída pela interface — o cadastro só
 * existia no comando `gestao:conta-bancaria`, rodado no servidor. Quem estava
 * abrindo a conciliação simplesmente travava ali.
 *
 * O ponto delicado não é o formulário, é o `is_default`: as liquidações vindas
 * das origens chegam sem banco (`contas`/`contasareceber` não têm a coluna) e
 * só entram na conciliação da conta padrão da empresa. Marcar padrão demais
 * move em silêncio todo o histórico de banco; marcar de menos abre conciliação
 * vazia. A regra travada aqui é a primeira conta sim, as seguintes não.
 */
class CadastroBancoNaTelaDeConciliacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private User $semPermissao;

    private int $empresaId;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome')->nullable();
            $table->string('username');
            $table->string('password')->nullable();
            $table->boolean('comercial')->default(false);
            $table->boolean('pagamentos')->default(false);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->string('banco', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->empresaId = (int) Conta::query()->create(['nome' => 'Europark'])->id;

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);
        $this->semPermissao = User::query()->create([
            'nome' => 'Visitante', 'username' => 'visitante', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operador->id, $this->semPermissao->id],
            'reconciliation.manage_user_ids' => [$this->operador->id],
            'gestao.legacy_ui' => false,
        ]);
    }

    /** @param array<string,string> $extra */
    private function cadastrar(array $extra = [], ?User $como = null)
    {
        return $this->actingAs($como ?? $this->operador)->post('/conciliacao/bancos', array_merge([
            'account_id' => $this->empresaId,
            'bank_name' => 'Banco Itaú',
            'bank_code' => '341',
            'agency' => '6260',
            'number' => '13377-9',
        ], $extra));
    }

    public function test_a_primeira_conta_da_empresa_e_cadastrada_como_padrao_e_ja_volta_selecionada(): void
    {
        $resposta = $this->cadastrar(['from' => '2026-08-01', 'to' => '2026-08-31']);

        $conta = BankAccount::query()->sole();

        $this->assertSame($this->empresaId, $conta->company_id);
        $this->assertSame('Europark', $conta->company_name);
        $this->assertSame('Banco Itaú - Agência 6260 - C/C 13377-9', $conta->fullLabel());
        $this->assertTrue($conta->active);

        // O motivo de existir a regra: sem padrão, nenhuma liquidação da
        // origem entraria e a conciliação abriria vazia.
        $this->assertTrue($conta->is_default);

        // Volta para a tela com a conta já escolhida e o período preservado —
        // senão a pessoa refaz o filtro inteiro depois de cadastrar.
        $resposta->assertRedirect(route('period-statements.create', [
            'account_id' => $this->empresaId,
            'from' => '2026-08-01',
            'to' => '2026-08-31',
            'bank_account_id' => $conta->id,
        ]));
    }

    public function test_a_segunda_conta_da_empresa_nao_rouba_o_padrao(): void
    {
        $this->cadastrar();
        $primeira = BankAccount::query()->sole();

        $this->cadastrar(['bank_name' => 'Banco do Brasil', 'bank_code' => '001', 'agency' => '1234', 'number' => '55555-0']);

        $segunda = BankAccount::query()->where('bank_code', '001')->sole();

        $this->assertFalse($segunda->is_default);
        $this->assertTrue($primeira->refresh()->is_default, 'A conta padrão da empresa não pode mudar sozinha.');
    }

    /**
     * Cadastrar a segunda conta muda o comportamento do sistema: a partir dela
     * a origem deixa de ter conta dedutível e as baixas novas ficam aguardando
     * atribuição (ADR-018). Descobrir isso pela conciliação vazia no dia
     * seguinte seria a pior forma de descobrir.
     */
    public function test_a_segunda_conta_avisa_que_as_baixas_novas_ficarao_sem_banco(): void
    {
        $this->cadastrar();

        $this->cadastrar(['bank_name' => 'Banco do Brasil', 'bank_code' => '001', 'agency' => '1234', 'number' => '55555-0'])
            ->assertSessionHas('warning', fn (string $aviso): bool => str_contains($aviso, 'aguardando definição de conta'));
    }

    public function test_a_primeira_conta_nao_avisa_nada(): void
    {
        $this->cadastrar()->assertSessionMissing('warning');
    }

    public function test_recusa_uma_conta_que_ja_pertence_a_outra_empresa(): void
    {
        $outraEmpresa = (int) Conta::query()->create(['nome' => 'Acop Files'])->id;
        BankAccount::query()->create([
            'company_id' => $outraEmpresa, 'company_name' => 'Acop Files',
            'bank_name' => 'Banco Itaú', 'bank_code' => '341',
            'agency' => '6260', 'number' => '13377-9',
            'active' => true, 'is_default' => true,
        ]);

        $this->cadastrar()->assertSessionHasErrors();

        // Roubar a conta corromperia a conciliação da outra empresa: nada muda.
        $conta = BankAccount::query()->sole();
        $this->assertSame($outraEmpresa, $conta->company_id);
    }

    public function test_adota_uma_conta_ja_cadastrada_que_ainda_nao_tinha_empresa(): void
    {
        // `gestao:conta-bancaria` cria sem vínculo quando o nome da empresa não
        // casa com o cadastro. Essa conta existe e está órfã, não é conflito.
        BankAccount::query()->create([
            'company_id' => null, 'company_name' => null,
            'bank_name' => 'Banco Itaú', 'bank_code' => '341',
            'agency' => '6260', 'number' => '13377-9', 'active' => true,
        ]);

        $this->cadastrar()->assertSessionHasNoErrors();

        $conta = BankAccount::query()->sole();
        $this->assertSame($this->empresaId, $conta->company_id);
        $this->assertTrue($conta->is_default);
    }

    public function test_exige_banco_agencia_e_numero(): void
    {
        $this->cadastrar(['bank_name' => '', 'agency' => '', 'number' => ''])
            ->assertSessionHasErrors(['bank_name', 'agency', 'number']);

        $this->assertSame(0, BankAccount::query()->count());
    }

    public function test_quem_so_pode_ver_nao_cadastra_banco(): void
    {
        $this->cadastrar(como: $this->semPermissao)->assertForbidden();

        $this->assertSame(0, BankAccount::query()->count());
    }

    public function test_a_tela_oferece_o_cadastro_a_mao_quando_a_empresa_nao_tem_banco(): void
    {
        $this->actingAs($this->operador)
            ->get('/conciliacao/nova?account_id='.$this->empresaId)
            ->assertOk()
            ->assertSee('Cadastrar o banco desta conta à mão')
            ->assertSee('Nenhum banco cadastrado nesta conta');
    }

    public function test_com_banco_cadastrado_a_tela_o_seleciona_e_recolhe_o_cadastro(): void
    {
        $this->cadastrar();
        $conta = BankAccount::query()->sole();

        $this->actingAs($this->operador)
            ->get('/conciliacao/nova?account_id='.$this->empresaId)
            ->assertOk()
            ->assertSee($conta->fullLabel())
            ->assertSee('Não achou o banco? Cadastrar outro à mão')
            ->assertDontSee('Nenhum banco cadastrado nesta conta');
    }
}
