<?php

namespace Tests\Feature;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Exceptions\TitleUpdateNotAllowed;
use App\Domain\Financial\TitleIngestionData;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O financeiro edita a origem DEPOIS de pagar.
 *
 * Em janeiro/2026 vinte títulos já liquidados tiveram, na origem, o número da
 * nota reformatado ("420270" virou "000.420.270"), a observação completada ou o
 * fornecedor corrigido. A sincronização recusava tudo em bloco e ficava
 * permanentemente em ERRO por causa de correção de texto.
 *
 * A regra certa separa as duas coisas: rótulo pode ser corrigido; dinheiro,
 * data e conta de um título já pago, não.
 */
class TituloLiquidadoCorrecaoDescritivaTest extends TestCase
{
    use RefreshDatabase;

    private function origem(): SourceSystem
    {
        return SourceSystem::query()->firstOrCreate(
            ['code' => 'LEGACY_PAYABLE'],
            ['name' => 'Contas a pagar legadas', 'active' => true],
        );
    }

    private function enviar(array $sobrescreve = []): FinancialTitle
    {
        $this->origem();

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(...array_merge([
            'sourceCode' => 'LEGACY_PAYABLE',
            'externalId' => '89608',
            'type' => FinancialTitleType::Payable,
            'issueDate' => '2026-07-01',
            'dueDate' => '2026-07-21',
            'originalAmount' => '331.50',
            'partyType' => 'SUPPLIER',
            'partyName' => 'Raizs Organicos',
            'documentNumber' => '420270',
            'notes' => 'Boleto baixado no banco',
            'installmentCount' => 1,
        ], $sobrescreve)))->title;
    }

    private function pagar(FinancialTitle $titulo): void
    {
        app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: '331.50',
            settlementDate: '2026-07-21',
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: 'baixa-89608',
        );
    }

    public function test_documento_reformatado_na_origem_e_aplicado_mesmo_pago(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        $this->enviar(['documentNumber' => '000.420.270']);

        $this->assertSame('000.420.270', $titulo->fresh()->document_number);
    }

    public function test_observacao_e_fornecedor_corrigidos_sao_aplicados_mesmo_pago(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        $this->enviar([
            'partyName' => 'Raizs Organicos Ltda',
            'notes' => 'Boleto baixado no banco | Frete 07/2026',
        ]);

        $atualizado = $titulo->fresh();
        $this->assertSame('Raizs Organicos Ltda', $atualizado->party_name);
        $this->assertSame('Boleto baixado no banco | Frete 07/2026', $atualizado->notes);
    }

    /**
     * O que a correção NÃO pode abrir: a baixa continua intocada e a situação
     * do título continua liquidada.
     */
    public function test_correcao_descritiva_nao_mexe_na_baixa_nem_na_situacao(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        $antes = $titulo->fresh();
        $this->enviar(['documentNumber' => '000.420.270']);
        $depois = $titulo->fresh();

        $this->assertSame($antes->status, $depois->status);
        $this->assertSame(
            (string) $antes->total_amount,
            (string) $depois->total_amount,
        );
        $this->assertSame(1, $depois->settlements()->count());
        $this->assertSame('331.50', (string) $depois->settlements()->first()->amount);
    }

    public function test_valor_de_titulo_pago_continua_recusado(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        $this->expectException(TitleUpdateNotAllowed::class);
        $this->expectExceptionMessageMatches('/o valor/');

        $this->enviar(['originalAmount' => '999.00']);
    }

    public function test_vencimento_de_titulo_pago_continua_recusado(): void
    {
        $titulo = $this->enviar();
        $this->pagar($titulo);

        $this->expectException(TitleUpdateNotAllowed::class);
        $this->expectExceptionMessageMatches('/o vencimento/');

        $this->enviar(['dueDate' => '2026-08-21']);
    }

    public function test_conta_de_titulo_pago_continua_recusada(): void
    {
        // `contas` e herdada do schema legado: quem a cria no servidor e a
        // propria sincronizacao, entao a suite a cria aqui.
        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->timestamps();
        });

        $conta = DB::table('contas')->insertGetId([
            'nome' => 'Itau', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $titulo = $this->enviar();
        $this->pagar($titulo);

        $this->expectException(TitleUpdateNotAllowed::class);
        $this->expectExceptionMessageMatches('/a conta/');

        $this->enviar(['accountId' => (int) $conta]);
    }
}
