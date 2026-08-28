<?php

namespace Tests\Feature;

use App\Application\Financial\PeriodStatementService;
use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Models\Conta;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use App\Models\TitleSettlement;
use App\Models\User;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reordenação manual (arrastar e soltar) das linhas de UM dia do Movimento
 * do período: o extrato do banco às vezes lista duas movimentações do mesmo
 * dia numa ordem que o critério padrão (data + registro no Gestão) não
 * reproduz, e a planilha precisa bater com o extrato exatamente. A ordem
 * escolhida precisa sobreviver a um `refresh()` posterior — inclusive o
 * automático a cada 5 minutos.
 */
class ReordenarLinhasDoDiaTest extends TestCase
{
    use RefreshDatabase;

    private User $operador;

    private User $visualizador;

    private int $contaId;

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

        $this->contaId = (int) Conta::query()->create(['nome' => 'Agro Colitti', 'banco' => 'Sicoob'])->id;

        $this->operador = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'),
        ]);
        $this->visualizador = User::query()->create([
            'nome' => 'Visualizador', 'username' => 'visualizador', 'password' => bcrypt('secret'),
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operador->id, $this->visualizador->id],
            'reconciliation.manage_user_ids' => [$this->operador->id],
            'gestao.legacy_ui' => false,
        ]);
    }

    private function titulo(FinancialTitleType $tipo, string $valor, string $externo, string $parte): FinancialTitle
    {
        $codigo = $tipo === FinancialTitleType::Payable ? 'LEGACY_PAYABLE' : 'LEGACY_RECEIVABLE';
        SourceSystem::query()->firstOrCreate(['code' => $codigo], ['name' => 'Origem', 'active' => true]);

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: $codigo, externalId: $externo, type: $tipo,
            issueDate: '2025-12-01', dueDate: '2026-01-20', originalAmount: $valor,
            discountAmount: '0.00', additionAmount: '0.00', partyName: $parte,
            documentNumber: 'DOC-'.$externo, accountId: $this->contaId, currency: 'BRL', installmentCount: 1,
        ))->title;
    }

    private function liquidar(FinancialTitle $titulo, string $valor, string $data): TitleSettlement
    {
        return app(SettlementService::class)->settle(
            titleId: $titulo->id, amount: $valor, settlementDate: $data,
            installmentId: $titulo->installments()->first()?->id,
            sourceSystemId: $titulo->source_system_id,
            externalId: 'liq-'.$titulo->external_id.'-'.$data,
        );
    }

    private function service(): PeriodStatementService
    {
        return app(PeriodStatementService::class);
    }

    public function test_reordenar_recalcula_o_saldo_do_dia_sem_alterar_dias_seguintes(): void
    {
        $a = $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '1', 'A'), '100.00', '2026-01-05');
        // usleep garante que "A" foi registrada primeiro, então a ordem
        // padrão (sem reordenação) seria A antes de B.
        usleep(1_100_000);
        $b = $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '2', 'B'), '30.00', '2026-01-05');
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '10.00', '3', 'C dia seguinte'), '10.00', '2026-01-06');

        $statement = $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $linhas = $statement->fresh('lines')->lines()->orderBy('line_number')->get();

        $this->assertSame('DOC-1', $linhas[0]->document_number, 'ordem padrão: A antes de B');
        $saldoFinalDoDia1Antes = $linhas[1]->running_balance_cents;
        $saldoDoDia2Antes = $linhas[2]->running_balance_cents;

        // Inverte: B antes de A.
        $this->service()->reordenarDia($statement, '2026-01-05', [$linhas[1]->id, $linhas[0]->id], $this->operador->id);

        $depois = $statement->fresh('lines')->lines()->orderBy('line_number')->get();
        $this->assertSame('DOC-2', $depois[0]->document_number, 'B passou a vir primeiro');
        $this->assertSame('DOC-1', $depois[1]->document_number);
        $this->assertSame($saldoFinalDoDia1Antes, $depois[1]->running_balance_cents, 'saldo ao final do dia 05 não muda com a ordem interna');
        $this->assertSame($saldoDoDia2Antes, $depois[2]->running_balance_cents, 'dia seguinte não é afetado');
    }

    public function test_ordem_sobrevive_a_um_refresh_posterior(): void
    {
        $a = $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '10', 'A'), '100.00', '2026-01-05');
        usleep(1_100_000);
        $b = $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '11', 'B'), '30.00', '2026-01-05');

        $statement = $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $linhas = $statement->fresh('lines')->lines()->orderBy('line_number')->get();

        $this->service()->reordenarDia($statement, '2026-01-05', [$linhas[1]->id, $linhas[0]->id], $this->operador->id);

        // Simula o sync automático de 5 em 5 minutos: um movimento novo,
        // sem relação nenhuma com o dia reordenado, dispara refresh().
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '5.00', '12', 'Novo, outro dia'), '5.00', '2026-01-20');
        $this->service()->refresh($statement, $this->operador->id);

        $depois = $statement->fresh('lines')->lines()->where('movement_date', '2026-01-05')->orderBy('line_number')->get();
        $this->assertSame('DOC-11', $depois[0]->document_number, 'B continua primeiro depois do refresh');
        $this->assertSame('DOC-10', $depois[1]->document_number);
    }

    public function test_conjunto_de_ids_incompleto_e_recusado(): void
    {
        $a = $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '20', 'A'), '100.00', '2026-01-05');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '21', 'B'), '30.00', '2026-01-05');

        $statement = $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $primeiraLinha = $statement->lines()->orderBy('line_number')->first();

        $this->expectException(DomainException::class);
        $this->service()->reordenarDia($statement, '2026-01-05', [$primeiraLinha->id], $this->operador->id);
    }

    public function test_linha_pendente_nao_pode_ser_incluida_na_reordenacao(): void
    {
        $this->titulo(FinancialTitleType::Payable, '50.00', '30', 'Pendente'); // nunca liquidado
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '31', 'Movimento'), '100.00', '2026-01-05');

        $statement = $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $pendente = $statement->lines()->where('section', 'PENDING')->first();
        $this->assertNotNull($pendente);

        $this->expectException(DomainException::class);
        $this->service()->reordenarDia($statement, $statement->period_end->toDateString(), [$pendente->id], $this->operador->id);
    }

    public function test_conciliacao_fechada_recusa_reordenar(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '40', 'A'), '100.00', '2026-01-05');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '41', 'B'), '30.00', '2026-01-05');

        $statement = $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $ids = $statement->lines()->orderBy('line_number')->pluck('id')->all();
        $this->service()->close($statement, $this->operador->id);

        $this->expectException(DomainException::class);
        $this->service()->reordenarDia($statement->fresh(), '2026-01-05', array_reverse($ids), $this->operador->id);
    }

    public function test_reordenar_pela_tela_persiste_e_responde_json(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '50', 'A'), '100.00', '2026-01-05');
        usleep(1_100_000);
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '51', 'B'), '30.00', '2026-01-05');

        $statement = $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $linhas = $statement->lines()->orderBy('line_number')->get();

        $this->actingAs($this->operador)
            ->postJson(route('period-statements.lines.reorder', $statement), [
                'movement_date' => '2026-01-05',
                'line_ids' => [$linhas[1]->id, $linhas[0]->id],
            ])
            ->assertOk();

        $this->assertSame('DOC-51', $statement->fresh('lines')->lines()->orderBy('line_number')->first()->document_number);
    }

    public function test_quem_so_visualiza_nao_pode_reordenar(): void
    {
        $this->liquidar($this->titulo(FinancialTitleType::Receivable, '100.00', '60', 'A'), '100.00', '2026-01-05');
        $this->liquidar($this->titulo(FinancialTitleType::Payable, '30.00', '61', 'B'), '30.00', '2026-01-05');

        $statement = $this->service()->create($this->contaId, '2026-01-01', '2026-01-31', 0, $this->operador->id);
        $ids = $statement->lines()->orderBy('line_number')->pluck('id')->all();

        $this->actingAs($this->visualizador)
            ->postJson(route('period-statements.lines.reorder', $statement), [
                'movement_date' => '2026-01-05',
                'line_ids' => array_reverse($ids),
            ])
            ->assertForbidden();
    }
}
