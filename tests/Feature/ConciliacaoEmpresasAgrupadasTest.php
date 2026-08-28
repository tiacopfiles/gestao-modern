<?php

namespace Tests\Feature;

use App\Application\Financial\ManualMovementService;
use App\Application\Financial\PeriodStatementService;
use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\FinancialTitle;
use App\Models\PeriodStatement;
use App\Models\SourceSystem;
use App\Models\TitleSettlement;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Agrocolitti: o cadastro legado (`contas`) traz a mesma empresa real como
 * dois registros separados, um por banco. A conciliação passa a tratá-los
 * como um grupo (ver `App\Domain\Financial\CompanyGroup`) — mas só ela;
 * contas a pagar/receber continuam vendo cada id como sempre viram.
 */
class ConciliacaoEmpresasAgrupadasTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private int $canonicoId;

    private int $membroId;

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

        $this->canonicoId = (int) Conta::query()->create(['nome' => 'Agro Colitti'])->id;
        $this->membroId = (int) Conta::query()->create(['nome' => 'Agro Colitti R'])->id;

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operador->id],
            'reconciliation.manage_user_ids' => [$this->operador->id],
            'gestao.legacy_ui' => false,
            // Os ids reais só existem depois do setUp (auto-increment) — o
            // grupo é configurado com os ids DESTE teste, não os de produção
            // (26/31), que ficam só no valor padrão em config/reconciliation.php.
            'reconciliation.company_groups' => [
                'agrocolitti' => [
                    'canonical_id' => $this->canonicoId,
                    'member_ids' => [$this->canonicoId, $this->membroId],
                    'display_name' => 'Agrocolitti',
                ],
            ],
        ]);
    }

    private function titulo(FinancialTitleType $tipo, string $valor, string $externo, string $vencimento, string $parte, int $contaId): FinancialTitle
    {
        $codigo = $tipo === FinancialTitleType::Payable ? 'LEGACY_PAYABLE' : 'LEGACY_RECEIVABLE';
        SourceSystem::query()->firstOrCreate(['code' => $codigo], ['name' => 'Origem', 'active' => true]);

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo,
            externalId: $externo,
            type: $tipo,
            issueDate: '2025-12-01',
            dueDate: $vencimento,
            originalAmount: $valor,
            discountAmount: '0.00',
            additionAmount: '0.00',
            partyName: $parte,
            documentNumber: 'DOC-'.$externo,
            accountId: $contaId,
            currency: 'BRL',
            installmentCount: 1,
        ))->title;
    }

    private function liquidar(FinancialTitle $titulo, string $valor, string $data, ?int $bankAccountId = null): TitleSettlement
    {
        return app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: $valor,
            settlementDate: $data,
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: 'liq-'.$titulo->external_id.'-'.$data,
            bankAccountId: $bankAccountId,
        );
    }

    private function manual(string $direcao, string $valor, string $data, string $historico, int $contaId): void
    {
        app(ManualMovementService::class)->create([
            'account_id' => $contaId,
            'movement_date' => $data,
            'direction' => $direcao,
            'amount' => $valor,
            'history' => $historico,
        ], $this->operador->id);
    }

    private function service(): PeriodStatementService
    {
        return app(PeriodStatementService::class);
    }

    public function test_dropdown_de_criacao_mostra_uma_unica_entrada_para_o_grupo(): void
    {
        $html = $this->actingAs($this->operador)
            ->get(route('period-statements.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Agrocolitti', $html);
        $this->assertStringNotContainsString('Agro Colitti R', $html);
        // "Agro Colitti" (nome original do canônico, sem o "R") não deveria
        // sobrar em lugar nenhum: foi substituído por "Agrocolitti" no dropdown.
        $this->assertStringNotContainsString('Agro Colitti<', $html);
    }

    public function test_previa_junta_titulos_e_movimentos_das_duas_empresas_reais(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '1000.00', '1', '2026-01-20', 'Cliente A', $this->canonicoId), '1000.00', '2026-01-05');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '400.00', '2', '2026-01-20', 'Fornecedor B', $this->membroId), '400.00', '2026-01-06');
        $this->manual('OUT', '35.00', '2026-01-07', 'Tarifa banco R', $this->membroId);

        $previa = $this->service()->preview($this->canonicoId, '2026-01-01', '2026-01-31');

        $this->assertCount(3, $previa['lines']);
        $this->assertSame(56500, $previa['closing_cents']); // 1000 - 400 - 35, em centavos
    }

    public function test_titulo_de_empresa_fora_do_grupo_continua_de_fora(): void
    {
        $outra = (int) Conta::query()->create(['nome' => 'Outra empresa qualquer'])->id;
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '999.00', '3', '2026-01-20', 'Estranha', $outra), '999.00', '2026-01-05');

        $previa = $this->service()->preview($this->canonicoId, '2026-01-01', '2026-01-31');

        $this->assertCount(0, $previa['lines']);
    }

    public function test_deducao_de_banco_sem_conta_e_avaliada_por_empresa_real_dentro_do_grupo(): void
    {
        // Cada empresa real do grupo tem sua PRÓPRIA conta única — a
        // dedução de "veio sem banco, mas só há uma conta possível" (ADR-018)
        // não pode misturar as duas.
        $bancoCanonico = BankAccount::query()->create([
            'company_id' => $this->canonicoId, 'bank_name' => 'Sicoob', 'agency' => '1', 'number' => '1', 'active' => true,
        ]);
        $bancoMembro = BankAccount::query()->create([
            'company_id' => $this->membroId, 'bank_name' => 'Banco R', 'agency' => '2', 'number' => '2', 'active' => true,
        ]);

        // Liquidação sem `bank_account_id` em cada empresa — a origem não
        // guarda banco, é o caso normal.
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '500.00', '4', '2026-01-20', 'A', $this->canonicoId), '500.00', '2026-01-05');
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '700.00', '5', '2026-01-20', 'B', $this->membroId), '700.00', '2026-01-05');

        // Filtrando explicitamente pela conta do CANÔNICO: só a liquidação
        // da empresa dona daquela conta única deveria herdar o banco.
        $previa = $this->service()->preview($this->canonicoId, '2026-01-01', '2026-01-31', 0, $bancoCanonico->id);

        $this->assertCount(1, $previa['lines']);
        $this->assertSame(50000, $previa['lines'][0]['amount_in_cents']);

        // O outro lado: filtrando pela conta do MEMBRO, só a liquidação dele entra.
        $previaMembro = $this->service()->preview($this->canonicoId, '2026-01-01', '2026-01-31', 0, $bancoMembro->id);

        $this->assertCount(1, $previaMembro['lines']);
        $this->assertSame(70000, $previaMembro['lines'][0]['amount_in_cents']);
    }

    public function test_refresh_de_conciliacao_mesclada_traz_movimento_novo_de_qualquer_um_dos_dois_lados(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '6', '2026-01-20', 'Inicial', $this->canonicoId), '100.00', '2026-01-05');

        $statement = $this->service()->create($this->canonicoId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $this->assertSame(1, $statement->line_count);
        $this->assertSame('Agrocolitti', $statement->account_name);

        $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '7', '2026-01-20', 'Novo do membro', $this->membroId), '30.00', '2026-01-06');

        $resultado = $this->service()->refresh($statement, $this->operador->id);

        $this->assertSame(1, $resultado->novos);
        $this->assertSame(2, $resultado->statement->line_count);
        $this->assertSame(7000, $resultado->statement->closing_balance_cents);
    }

    public function test_criar_pela_tela_grava_conciliacao_unica_para_o_grupo(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '200.00', '8', '2026-01-20', 'Fornecedor', $this->membroId), '200.00', '2026-01-10');

        $this->actingAs($this->operador)
            ->post(route('period-statements.store'), [
                'account_id' => $this->canonicoId,
                'from' => '2026-01-01',
                'to' => '2026-01-31',
                'opening' => '1.000,00',
            ])
            ->assertRedirect();

        $this->assertSame(1, PeriodStatement::query()->count());
        $statement = PeriodStatement::query()->latest('id')->first();
        $this->assertSame($this->canonicoId, $statement->account_id);
        $this->assertNull($statement->bank_account_id, 'grupo mesclado não filtra por um banco só');
        $this->assertSame(80000, $statement->closing_balance_cents);
    }

    public function test_titulos_das_duas_empresas_continuam_com_seus_ids_originais(): void
    {
        $tituloCanonico = $this->titulo(FinancialTitleType::Receivable, '500.00', '9', '2026-01-20', 'A', $this->canonicoId);
        $tituloMembro = $this->titulo(FinancialTitleType::Payable, '300.00', '10', '2026-01-20', 'B', $this->membroId);
        $this->liquidar($tituloCanonico, '500.00', '2026-01-05');
        $this->liquidar($tituloMembro, '300.00', '2026-01-06');

        $this->service()->create($this->canonicoId, '2026-01-01', '2026-01-31', 0, $this->operador->id);

        // A conciliação mesclou o MOVIMENTO; o cadastro de origem de cada
        // título continua apontando para a empresa real que sempre teve.
        $this->assertSame($this->canonicoId, $tituloCanonico->fresh()->account_id);
        $this->assertSame($this->membroId, $tituloMembro->fresh()->account_id);
        $this->assertSame(2, Conta::query()->count(), 'as duas empresas do cadastro continuam existindo separadas');
    }
}
