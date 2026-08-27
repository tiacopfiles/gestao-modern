<?php

namespace Tests\Feature;

use App\Application\Banking\BankLedgerService;
use App\Application\Financial\TitleIngestionService;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Money;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

/**
 * Extrato com saldo corrido.
 *
 * Responde as quatro perguntas que nenhuma tela do núcleo moderno respondia:
 * quanto tinha, quanto entrou, quanto saiu, quanto ficou — e qual movimento
 * produziu cada saldo.
 */
class BankLedgerTest extends TestCase
{
    use RefreshesTestDatabase;

    private User $operator;

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
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('contas')->insert(['id' => 1, 'nome' => 'Conta sintética', 'created_at' => now(), 'updated_at' => now()]);

        $this->operator = User::query()->create([
            'nome' => 'Operador', 'username' => 'operador', 'password' => bcrypt('secret'), 'pagamentos' => true,
        ]);

        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id],
            'reconciliation.manage_user_ids' => [$this->operator->id],
        ]);
    }

    private function transaction(string $direction, string $amount, string $date, string $externalId, string $desc = 'Movimento sintético'): BankTransaction
    {
        $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();
        $batch = ImportBatch::query()->firstOrCreate(
            ['account_id' => 1, 'channel' => 'API', 'correlation_id' => 'ledger-batch'],
            ['source_system_id' => $source->id, 'format' => 'CANONICAL_API', 'status' => 'COMPLETED',
                'total_items' => 0, 'imported_items' => 0, 'started_at' => now(), 'completed_at' => now()],
        );

        return BankTransaction::query()->create([
            'account_id' => 1, 'source_system_id' => $source->id, 'import_batch_id' => $batch->id,
            'external_id' => $externalId, 'identity_quality' => 'STRONG', 'direction' => $direction,
            'amount' => $amount, 'currency' => 'BRL', 'transaction_date' => $date,
            'description_original' => $desc,
            'payload_hash' => hash('sha256', $externalId), 'raw_hash' => hash('sha256', 'raw-'.$externalId),
        ]);
    }

    private function ledger(int $openingCents = 0, ...$args): array
    {
        return app(BankLedgerService::class)->build(1, '2026-05-01', '2026-05-31', $openingCents, ...$args);
    }

    public function test_running_balance_follows_the_canonical_scenario(): void
    {
        // 10.000 → −1.000 → +2.500 = 11.500
        $this->transaction('DEBIT', '1000.00', '2026-05-05', 'TX-PAG', 'Pagamento fornecedor');
        $this->transaction('CREDIT', '2500.00', '2026-05-10', 'TX-REC', 'Recebimento cliente');

        $data = $this->ledger(Money::toCents('10000.00'));

        $this->assertSame(1000000, $data['opening_cents']);
        $this->assertCount(2, $data['lines']);

        $this->assertSame(-100000, $data['lines'][0]['signed_cents']);
        $this->assertSame(900000, $data['lines'][0]['balance_cents'], 'Saldo após o pagamento deve ser 9.000,00');

        $this->assertSame(250000, $data['lines'][1]['signed_cents']);
        $this->assertSame(1150000, $data['lines'][1]['balance_cents'], 'Saldo após o recebimento deve ser 11.500,00');

        $this->assertSame(1150000, $data['closing_cents']);
        $this->assertSame(250000, $data['credits_cents']);
        $this->assertSame(100000, $data['debits_cents']);

        // Saldo final = inicial + entradas − saídas
        $this->assertSame(
            $data['opening_cents'] + $data['credits_cents'] - $data['debits_cents'],
            $data['closing_cents'],
        );
    }

    public function test_opening_balance_defaults_to_zero_and_accepts_negative(): void
    {
        $this->transaction('CREDIT', '100.00', '2026-05-02', 'TX-A');

        $this->assertSame(0, $this->ledger()['opening_cents']);
        $this->assertSame(10000, $this->ledger()['closing_cents']);

        $negative = $this->ledger(-50000);
        $this->assertSame(-50000, $negative['opening_cents']);
        $this->assertSame(-40000, $negative['closing_cents']);
    }

    public function test_lines_are_ordered_by_date_so_the_balance_makes_sense(): void
    {
        $this->transaction('CREDIT', '10.00', '2026-05-20', 'TX-LATE');
        $this->transaction('CREDIT', '20.00', '2026-05-02', 'TX-EARLY');

        $lines = $this->ledger()['lines'];

        $this->assertSame('2026-05-02', $lines[0]['date']->toDateString());
        $this->assertSame(2000, $lines[0]['balance_cents']);
        $this->assertSame(3000, $lines[1]['balance_cents']);
    }

    public function test_transactions_outside_the_period_or_account_are_excluded(): void
    {
        $this->transaction('CREDIT', '100.00', '2026-04-30', 'TX-BEFORE');
        $this->transaction('CREDIT', '100.00', '2026-06-01', 'TX-AFTER');
        $this->transaction('CREDIT', '55.00', '2026-05-15', 'TX-INSIDE');

        $data = $this->ledger();

        $this->assertCount(1, $data['lines']);
        $this->assertSame(5500, $data['closing_cents']);
    }

    public function test_reconciliation_status_and_linked_titles_are_shown(): void
    {
        $transaction = $this->transaction('DEBIT', '300.00', '2026-05-08', 'TX-CONC');
        $this->transaction('DEBIT', '77.00', '2026-05-09', 'TX-PEND');

        $title = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: 'T-CONC', type: FinancialTitleType::Payable,
            issueDate: '2026-05-01', dueDate: '2026-05-08', originalAmount: '300.00',
            partyName: 'Fornecedor', documentNumber: 'NF-777', accountId: 1,
        ), $this->operator->id)->title->load('installments');

        $session = app(ReconciliationSessionService::class)
            ->create(1, '2026-05-01', '2026-05-31', $this->operator->id, (string) Str::uuid());
        app(ManualReconciliationService::class)->confirm(
            $session->id,
            [new ReconciliationTitleAllocationData($title->id, $title->installments->first()->id, '300.00')],
            [new ReconciliationTransactionAllocationData($transaction->id, '300.00')],
            $this->operator->id, (string) Str::uuid(),
        );

        $data = $this->ledger();
        $conciliated = collect($data['lines'])->firstWhere('transaction_id', $transaction->id);

        $this->assertSame('CONCILIADO', $conciliated['status']);
        $this->assertContains('NF-777', $conciliated['titles']);

        $pending = collect($data['lines'])->first(fn ($l) => $l['transaction_id'] !== $transaction->id);
        $this->assertSame('NAO_CONCILIADO', $pending['status']);

        $this->assertSame(30000, $data['reconciled_cents']);
        $this->assertSame(7700, $data['unreconciled_cents']);
    }

    public function test_filters_hide_lines_without_corrupting_the_running_balance(): void
    {
        $this->transaction('CREDIT', '100.00', '2026-05-02', 'TX-IN');
        $this->transaction('DEBIT', '40.00', '2026-05-03', 'TX-OUT');

        // Filtrar por entradas esconde a saída da lista, mas o saldo exibido
        // continua sendo o saldo real da conta — 60,00 e não 100,00. Um extrato
        // que recalculasse o saldo só com as linhas visíveis mentiria.
        $onlyCredits = $this->ledger(0, 'CREDIT');

        $this->assertCount(1, $onlyCredits['lines']);
        $this->assertSame(6000, $onlyCredits['closing_cents']);
        $this->assertSame(10000, $onlyCredits['credits_cents']);
        $this->assertSame(4000, $onlyCredits['debits_cents']);
    }

    public function test_screen_renders_and_respects_flag_and_permission(): void
    {
        $this->transaction('CREDIT', '2500.00', '2026-05-10', 'TX-VIEW', 'Recebimento cliente Nova');

        $this->actingAs($this->operator)
            ->get('/extrato?account_id=1&from=2026-05-01&to=2026-05-31&opening_balance=10.000,00')
            ->assertOk()
            ->assertSee('Recebimento cliente Nova')
            ->assertSee('10.000,00')
            ->assertSee('12.500,00');

        config(['reconciliation.v2_enabled' => false]);
        $this->actingAs($this->operator)->get('/extrato')->assertNotFound();
    }

    public function test_csv_export_includes_opening_and_closing_lines(): void
    {
        $this->transaction('DEBIT', '1000.00', '2026-05-05', 'TX-CSV');

        $response = $this->actingAs($this->operator)
            ->get('/extrato/exportar?account_id=1&from=2026-05-01&to=2026-05-31&opening_balance=10000,00');

        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('SALDO INICIAL', $csv);
        $this->assertStringContainsString('SALDO FINAL', $csv);
        $this->assertStringContainsString('9000.00', $csv);
    }
}
