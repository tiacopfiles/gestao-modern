<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Conta;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Empresa e conta bancária são coisas diferentes.
 *
 * O sistema antigo guarda "Acop Files", "Global Box" e "Duemagem" num campo
 * chamado `conta`, mas isso é a EMPRESA. As planilhas de conciliação deixam a
 * separação explícita — empresa numa linha, "Banco Itaú - Agência 6260 - C/C
 * 13377-9" na outra — e é a conta bancária que tem extrato e saldo.
 */
class BankAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->string('banco', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function test_uma_empresa_pode_ter_varias_contas_bancarias(): void
    {
        $empresa = Conta::query()->create(['nome' => 'Acop Files']);

        BankAccount::query()->create([
            'company_id' => $empresa->id, 'company_name' => $empresa->nome,
            'bank_name' => 'Banco Itaú', 'bank_code' => '341', 'agency' => '6260', 'number' => '13377-9',
        ]);
        BankAccount::query()->create([
            'company_id' => $empresa->id, 'company_name' => $empresa->nome,
            'bank_name' => 'Banco do Brasil', 'bank_code' => '001', 'agency' => '1234', 'number' => '55555-0',
        ]);

        $this->assertSame(2, BankAccount::query()->where('company_id', $empresa->id)->count());
    }

    public function test_a_mesma_conta_nao_pode_ser_cadastrada_duas_vezes(): void
    {
        $dados = ['bank_name' => 'Banco Itaú', 'bank_code' => '341', 'agency' => '6260', 'number' => '13377-9'];

        BankAccount::query()->create($dados);

        $this->expectException(UniqueConstraintViolationException::class);
        BankAccount::query()->create($dados);
    }

    public function test_rotulo_reproduz_o_cabecalho_da_planilha(): void
    {
        $conta = new BankAccount([
            'bank_name' => 'Banco Itaú', 'agency' => '6260', 'number' => '13377-9',
        ]);

        $this->assertSame('Banco Itaú - Agência 6260 - C/C 13377-9', $conta->fullLabel());
    }

    /**
     * A regra que importa: sem evidência, o vínculo fica em branco. Associar
     * "por parecer" produziria um dado que aparenta ser informação e não é.
     */
    public function test_empresa_desconhecida_nao_vira_vinculo_por_semelhanca(): void
    {
        Conta::query()->create(['nome' => 'Acop Files']);

        Artisan::call('gestao:conta-bancaria', [
            '--banco' => 'Banco Itaú', '--codigo' => '341',
            '--agencia' => '4536', '--numero' => '39538-9',
            '--empresa' => 'Global Box Tecnologia',   // nome que NÃO existe no cadastro
        ]);

        $conta = BankAccount::query()->where('agency', '4536')->first();

        $this->assertNotNull($conta);
        $this->assertNull($conta->company_id, 'ligou a conta a uma empresa sem evidência');
        $this->assertStringContainsString('não confirmado', (string) $conta->company_name);
    }

    public function test_empresa_existente_vira_vinculo_confirmado(): void
    {
        $empresa = Conta::query()->create(['nome' => 'Acop Files']);

        Artisan::call('gestao:conta-bancaria', [
            '--banco' => 'Banco Itaú', '--codigo' => '341',
            '--agencia' => '6260', '--numero' => '13377-9',
            '--empresa' => 'acop files',   // caixa diferente, mesmo cadastro
        ]);

        $conta = BankAccount::query()->where('agency', '6260')->first();

        $this->assertSame($empresa->id, $conta->company_id);
        $this->assertSame('Acop Files', $conta->company_name);
    }
}
