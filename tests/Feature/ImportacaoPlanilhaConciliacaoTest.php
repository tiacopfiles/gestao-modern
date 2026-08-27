<?php

namespace Tests\Feature;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Models\BankAccount;
use App\Models\Conta;
use App\Models\ManualMovement;
use App\Models\SourceSystem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Importação das linhas das planilhas de conciliação que não existem nas
 * origens — tarifa, rendimento, transferência entre contas, e recebimentos que
 * ninguém chegou a cadastrar.
 *
 * O risco desta importação não é falhar: é funcionar duas vezes. Rodar o mesmo
 * arquivo de novo, ou importar uma linha que já chega pela sincronização,
 * dobraria dinheiro num sistema financeiro. É isso que estes testes travam.
 */
class ImportacaoPlanilhaConciliacaoTest extends TestCase
{
    use RefreshDatabase;

    private int $contaId;

    private BankAccount $itau;

    private string $arquivo;

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

        $this->contaId = (int) Conta::query()->create(['nome' => 'Acop Files'])->id;

        $this->itau = BankAccount::query()->create([
            'company_id' => $this->contaId, 'company_name' => 'Acop Files',
            'bank_name' => 'Banco Itaú', 'bank_code' => '341',
            'agency' => '6260', 'number' => '13377-9',
            'active' => true, 'is_default' => true,
        ]);

        $this->arquivo = tempnam(sys_get_temp_dir(), 'imp').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $linhas */
    private function arquivoCom(array $linhas): string
    {
        file_put_contents($this->arquivo, json_encode($linhas));

        return $this->arquivo;
    }

    private function linha(array $sobrescreve = []): array
    {
        return array_merge([
            'empresa' => 'Acop Files',
            'aba' => 'Janeiro-2026',
            'linha_planilha' => 12,
            'movement_date' => '2026-01-05',
            'document_number' => null,
            'direction' => 'OUT',
            'amount' => '119.40',
            'history' => 'TAR Cobrança EXP',
            'import_key' => 'xlsx:'.md5((string) random_int(1, PHP_INT_MAX)),
        ], $sobrescreve);
    }

    public function test_importa_a_linha_e_liga_na_conta_bancaria_padrao(): void
    {
        $this->artisan('gestao:importar-movimentos-planilha', ['arquivo' => $this->arquivoCom([
            $this->linha(['history' => 'Rend Pago Aplic Aut APR', 'direction' => 'IN', 'amount' => '0.90']),
        ])])->assertSuccessful();

        $m = ManualMovement::query()->first();

        $this->assertNotNull($m);
        $this->assertSame($this->contaId, (int) $m->account_id);
        $this->assertSame($this->itau->id, (int) $m->bank_account_id);
        $this->assertSame('Rend Pago Aplic Aut APR', $m->history);
        $this->assertSame('0.90', (string) $m->amount);
        $this->assertStringContainsString('Janeiro-2026', (string) $m->notes);
    }

    /**
     * O teste que mais importa: rodar o mesmo arquivo duas vezes não pode criar
     * dinheiro do nada.
     */
    public function test_rodar_duas_vezes_nao_duplica(): void
    {
        $arquivo = $this->arquivoCom([$this->linha(['import_key' => 'xlsx:fixa-1'])]);

        $this->artisan('gestao:importar-movimentos-planilha', ['arquivo' => $arquivo])->assertSuccessful();
        $this->artisan('gestao:importar-movimentos-planilha', ['arquivo' => $arquivo])->assertSuccessful();

        $this->assertSame(1, ManualMovement::query()->count());
    }

    /**
     * Duas tarifas iguais no mesmo dia acontecem de verdade — aparecem lado a
     * lado nas planilhas. Elas têm chaves diferentes e as duas têm de entrar.
     */
    public function test_linhas_legitimamente_iguais_no_mesmo_dia_entram_as_duas(): void
    {
        $this->artisan('gestao:importar-movimentos-planilha', ['arquivo' => $this->arquivoCom([
            $this->linha(['import_key' => 'xlsx:a', 'amount' => '99.50']),
            $this->linha(['import_key' => 'xlsx:b', 'amount' => '99.50']),
        ])])->assertSuccessful();

        $this->assertSame(2, ManualMovement::query()->count());
    }

    public function test_modo_seco_nao_grava_nada(): void
    {
        $this->artisan('gestao:importar-movimentos-planilha', [
            'arquivo' => $this->arquivoCom([$this->linha()]),
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, ManualMovement::query()->count());
    }

    /**
     * Empresa que não existe no cadastro é PULADA, não adivinhada. Jogar
     * dinheiro na empresa errada é pior do que não importar.
     */
    public function test_empresa_desconhecida_e_pulada(): void
    {
        $this->artisan('gestao:importar-movimentos-planilha', ['arquivo' => $this->arquivoCom([
            $this->linha(['empresa' => 'Empresa Que Nao Existe']),
        ])])->assertSuccessful();

        $this->assertSame(0, ManualMovement::query()->count());
    }

    public function test_filtro_por_empresa_limita_o_que_entra(): void
    {
        Conta::query()->create(['nome' => 'Duemagem']);

        $this->artisan('gestao:importar-movimentos-planilha', [
            'arquivo' => $this->arquivoCom([
                $this->linha(['empresa' => 'Acop Files']),
                $this->linha(['empresa' => 'Duemagem']),
            ]),
            '--empresa' => 'Duemagem',
        ])->assertSuccessful();

        $this->assertSame(1, ManualMovement::query()->count());
        $this->assertNotSame($this->contaId, (int) ManualMovement::query()->first()->account_id);
    }

    /**
     * Uma linha que coincide com liquidação já existente é IMPORTADA, mas
     * reportada. O comando avisa; quem decide é quem conhece a operação.
     */
    public function test_coincidencia_com_liquidacao_existente_e_reportada(): void
    {
        SourceSystem::query()->firstOrCreate(['code' => 'LEGACY_PAYABLE'], ['name' => 'Origem', 'active' => true]);
        $titulo = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'LEGACY_PAYABLE',
            externalId: 'X1',
            type: FinancialTitleType::Payable,
            issueDate: '2026-01-01',
            dueDate: '2026-01-05',
            originalAmount: '119.40',
            discountAmount: '0.00',
            additionAmount: '0.00',
            partyName: 'Fornecedor',
            accountId: $this->contaId,
            currency: 'BRL',
            installmentCount: 1,
        ))->title;

        app(SettlementService::class)->settle(
            titleId: $titulo->id,
            amount: '119.40',
            settlementDate: '2026-01-05',
            installmentId: $titulo->installments()->first()?->id,
        );

        $this->artisan('gestao:importar-movimentos-planilha', [
            'arquivo' => $this->arquivoCom([$this->linha()]),
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Batem com liquidação existente')
            ->assertSuccessful();
    }

    public function test_arquivo_inexistente_falha_sem_gravar(): void
    {
        $this->artisan('gestao:importar-movimentos-planilha', ['arquivo' => 'C:\\nao\\existe.json'])
            ->assertFailed();

        $this->assertSame(0, ManualMovement::query()->count());
    }
}
