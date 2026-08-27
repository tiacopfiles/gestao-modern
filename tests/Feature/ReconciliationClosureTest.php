<?php

namespace Tests\Feature;

use App\Application\Financial\TitleIngestionService;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationCandidateService;
use App\Application\Reconciliation\ReconciliationClosureHashService;
use App\Application\Reconciliation\ReconciliationClosureService;
use App\Application\Reconciliation\ReconciliationExceptionService;
use App\Application\Reconciliation\ReconciliationMatchingEngine;
use App\Application\Reconciliation\ReconciliationReopeningService;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\ImportBatch;
use App\Models\ReconciliationCandidate;
use App\Models\ReconciliationClosure;
use App\Models\ReconciliationException;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationSession;
use App\Models\SourceSystem;
use App\Models\TitleInstallment;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CreatesLegacyWitnessTables;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

class ReconciliationClosureTest extends TestCase
{
    use CreatesLegacyWitnessTables;
    use RefreshesTestDatabase;

    private User $operator;

    private static int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome')->nullable();
            $table->string('username');
            $table->string('email')->nullable();
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
            $table->string('nome_detalhado')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('contas')->insert([
            ['id' => 1, 'nome' => 'Banco A', 'nome_detalhado' => 'Conta A', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nome' => 'Banco B', 'nome_detalhado' => 'Conta B', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $this->createLegacyWitnessTables();

        $this->operator = User::query()->create(['nome' => 'Operador autorizado', 'username' => 'operador', 'password' => bcrypt('secret')]);
        config([
            'reconciliation.v2_enabled' => true,
            'reconciliation.closing_enabled' => true,
            'reconciliation.matching_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id],
            'reconciliation.manage_user_ids' => [$this->operator->id],
            'reconciliation.close_user_ids' => [$this->operator->id],
            'reconciliation.reopen_user_ids' => [$this->operator->id],
        ]);
    }

    public function test_feature_flag_disabled_returns_404_and_manual_reconciliation_still_works(): void
    {
        $session = $this->reconciliationSession();
        config(['reconciliation.closing_enabled' => false]);

        $this->actingAs($this->operator)->get(route('reconciliation-v2.closure.create', $session))->assertNotFound();
        $this->actingAs($this->operator)->get(route('reconciliation-v2.show', $session))->assertOk();
    }

    public function test_closing_flag_alone_is_not_enough_without_v2_enabled(): void
    {
        $session = $this->reconciliationSession();
        config(['reconciliation.v2_enabled' => false]);

        $this->actingAs($this->operator)->get(route('reconciliation-v2.closure.create', $session))->assertNotFound();
    }

    public function test_close_requires_permission(): void
    {
        $session = $this->reconciliationSession();
        config(['reconciliation.close_user_ids' => []]);

        $this->actingAs($this->operator)->get(route('reconciliation-v2.closure.create', $session))->assertForbidden();
        $this->actingAs($this->operator)->post(route('reconciliation-v2.closure.store', $session), ['confirm' => '1'])->assertForbidden();
    }

    public function test_reopen_requires_permission(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        $closure = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        config(['reconciliation.reopen_user_ids' => []]);
        $this->actingAs($this->operator)
            ->post(route('reconciliation-v2.closure.reopen', [$session, $closure]), ['reason' => 'teste'])
            ->assertForbidden();
    }

    public function test_close_creates_closure_snapshot_hash_metrics_and_audit_events(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '250.00');
        $transaction = $this->transaction('DEBIT', '250.00');
        $this->confirm($session, [[$title, null, '250.00']], [[$transaction, '250.00']], 'closure-correlation');

        $closure = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, 'close-correlation');

        $this->assertSame(1, $closure->sequence_number);
        $this->assertSame('CLOSED', $closure->status->value);
        $this->assertNull($closure->previous_closure_id);
        $this->assertSame(64, strlen($closure->closure_hash));
        $this->assertSame('closure-snapshot-v1', $closure->schema_version);
        $this->assertSame('CLOSED', $session->fresh()->status->value);
        $this->assertDatabaseHas('reconciliation_closure_matches', ['reconciliation_closure_id' => $closure->id, 'captured_status' => 'CONFIRMED', 'captured_total_amount' => '250.00']);
        $this->assertDatabaseHas('reconciliation_closure_metrics', ['reconciliation_closure_id' => $closure->id, 'metric_key' => 'reconciled_amount', 'metric_value' => '250.0000']);
        $this->assertDatabaseHas('audit_events', ['action' => 'RECONCILIATION_CLOSURE_CREATED', 'entity_id' => (string) $session->id, 'correlation_id' => 'close-correlation']);
        $this->assertDatabaseHas('audit_events', ['action' => 'RECONCILIATION_CLOSURE_COMPLETED', 'entity_id' => (string) $closure->id, 'correlation_id' => 'close-correlation']);
    }

    public function test_double_close_fails_with_already_closed_and_creates_no_second_row(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        $this->assertRule(fn () => app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid()), 'CLOSURE_SESSION_ALREADY_CLOSED');
        $this->assertDatabaseCount('reconciliation_closures', 1);
    }

    public function test_close_blocks_on_open_exception_and_allows_when_justified(): void
    {
        $session = $this->reconciliationSession();
        $exception = $this->exception($session);

        $this->assertRule(fn () => app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid()), 'CLOSURE_OPEN_EXCEPTIONS');

        app(ReconciliationExceptionService::class)->justify($session->id, $exception->id, 'Divergência revisada manualmente', $this->operator->id, (string) Str::uuid());
        $closure = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());
        $this->assertSame('CLOSED', $closure->status->value);
        $this->assertDatabaseHas('reconciliation_closure_exceptions', ['reconciliation_closure_id' => $closure->id, 'reconciliation_exception_id' => $exception->id, 'captured_status' => 'JUSTIFIED']);
    }

    public function test_close_blocks_on_period_overlap_but_not_against_reopened_closure(): void
    {
        $sessionA = $this->reconciliationSession(1, '2026-08-01', '2026-08-31');
        $titleA = $this->title(FinancialTitleType::Payable, '100.00');
        $transactionA = $this->transaction('DEBIT', '100.00');
        $this->confirm($sessionA, [[$titleA, null, '100.00']], [[$transactionA, '100.00']]);
        $closureA = app(ReconciliationClosureService::class)->close($sessionA->id, $this->operator->id, (string) Str::uuid());

        $sessionB = $this->reconciliationSession(1, '2026-08-15', '2026-09-15');
        $titleB = $this->title(FinancialTitleType::Payable, '50.00');
        $transactionB = $this->transaction('DEBIT', '50.00', 1, '2026-08-20');
        $this->confirm($sessionB, [[$titleB, null, '50.00']], [[$transactionB, '50.00']]);
        $this->assertRule(fn () => app(ReconciliationClosureService::class)->close($sessionB->id, $this->operator->id, (string) Str::uuid()), 'CLOSURE_PERIOD_OVERLAP');

        app(ReconciliationReopeningService::class)->reopen($sessionA->id, $closureA->id, 'ajuste solicitado', $this->operator->id, (string) Str::uuid());
        $closureB = app(ReconciliationClosureService::class)->close($sessionB->id, $this->operator->id, (string) Str::uuid());
        $this->assertSame('CLOSED', $closureB->status->value);
    }

    public function test_hash_is_deterministic_order_independent_and_sensitive_to_content(): void
    {
        $service = app(ReconciliationClosureHashService::class);
        $payload = [
            'schema_version' => 'closure-snapshot-v1', 'engine_version' => 'rules-v1',
            'account_id' => 1, 'reconciliation_session_id' => 1,
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
            'matches' => [['match_id' => 1, 'status' => 'CONFIRMED', 'total_amount' => '100.00'], ['match_id' => 2, 'status' => 'CONFIRMED', 'total_amount' => '50.00']],
            'exceptions' => [], 'metrics' => [['metric_key' => 'credit_total', 'metric_value' => '150.00']],
        ];
        $reordered = $payload;
        $reordered['matches'] = array_reverse($payload['matches']);

        $this->assertSame($service->hash($payload), $service->hash($payload));
        $this->assertSame($service->hash($payload), $service->hash($reordered));

        $changed = $payload;
        $changed['matches'][0]['status'] = 'VOIDED';
        $this->assertNotSame($service->hash($payload), $service->hash($changed));

        $versionChanged = $payload;
        $versionChanged['schema_version'] = 'closure-snapshot-v2';
        $this->assertNotSame($service->hash($payload), $service->hash($versionChanged));
    }

    public function test_reopen_creates_reopening_updates_statuses_and_audits(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        $closure = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        $reopened = app(ReconciliationReopeningService::class)->reopen($session->id, $closure->id, 'ajuste solicitado pelo financeiro', $this->operator->id, 'reopen-correlation');

        $this->assertSame('REOPENED', $reopened->status->value);
        $this->assertSame('REOPENED', $session->fresh()->status->value);
        $this->assertDatabaseHas('reconciliation_reopenings', ['reconciliation_closure_id' => $closure->id, 'reason' => 'ajuste solicitado pelo financeiro', 'previous_status' => 'CLOSED', 'resulting_session_status' => 'REOPENED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'RECONCILIATION_CLOSURE_REOPENED', 'entity_id' => (string) $closure->id, 'correlation_id' => 'reopen-correlation']);
        $this->assertNotNull(ReconciliationClosure::query()->find($closure->id)->closure_hash);
    }

    public function test_reopen_without_reason_fails(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        $closure = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        $this->assertRule(fn () => app(ReconciliationReopeningService::class)->reopen($session->id, $closure->id, '   ', $this->operator->id, (string) Str::uuid()), 'CLOSURE_REOPEN_REASON_REQUIRED');
    }

    public function test_reopen_of_non_closed_fails(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        $closure = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());
        app(ReconciliationReopeningService::class)->reopen($session->id, $closure->id, 'primeira reabertura', $this->operator->id, (string) Str::uuid());

        $this->assertRule(fn () => app(ReconciliationReopeningService::class)->reopen($session->id, $closure->id, 'segunda tentativa', $this->operator->id, (string) Str::uuid()), 'CLOSURE_NOT_CLOSED');
    }

    public function test_reclose_cycle_creates_second_closure_linked_to_the_first(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        $first = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        app(ReconciliationReopeningService::class)->reopen($session->id, $first->id, 'ajuste', $this->operator->id, (string) Str::uuid());
        $secondTitle = $this->title(FinancialTitleType::Payable, '30.00');
        $secondTransaction = $this->transaction('DEBIT', '30.00');
        $this->confirm($session, [[$secondTitle, null, '30.00']], [[$secondTransaction, '30.00']]);
        $second = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        $this->assertSame(2, $second->sequence_number);
        $this->assertSame($first->id, $second->previous_closure_id);
        $this->assertNotSame($first->closure_hash, $second->closure_hash);
        $this->assertDatabaseHas('reconciliation_closures', ['id' => $first->id, 'sequence_number' => 1, 'closure_hash' => $first->closure_hash]);
        $this->assertDatabaseHas('audit_events', ['action' => 'RECONCILIATION_CLOSURE_RECLOSED', 'entity_id' => (string) $second->id]);
    }

    public function test_writes_are_blocked_after_session_is_closed(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        $anotherTitle = $this->title(FinancialTitleType::Payable, '10.00');
        $anotherTransaction = $this->transaction('DEBIT', '10.00');
        $this->assertRule(
            fn () => $this->confirm($session, [[$anotherTitle, null, '10.00']], [[$anotherTransaction, '10.00']]),
            'RECONCILIATION_SESSION_CLOSED',
        );

        $match = ReconciliationMatch::query()->where('reconciliation_session_id', $session->id)->firstOrFail();
        $this->assertRule(
            fn () => app(ManualReconciliationService::class)->void($session->id, $match->id, 'tentativa após fechamento', $this->operator->id, (string) Str::uuid()),
            'RECONCILIATION_SESSION_CLOSED',
        );

        $exception = $this->exception($session);
        $this->assertRule(
            fn () => app(ReconciliationExceptionService::class)->justify($session->id, $exception->id, 'tentativa após fechamento', $this->operator->id, (string) Str::uuid()),
            'RECONCILIATION_SESSION_CLOSED',
        );

        config(['reconciliation.matching_enabled' => true]);
        $this->assertRule(
            fn () => app(ReconciliationMatchingEngine::class)->generate($session->id, $this->operator->id, (string) Str::uuid()),
            'RECONCILIATION_SESSION_CLOSED',
        );
    }

    public function test_candidate_accept_and_reject_are_blocked_after_close(): void
    {
        config(['reconciliation.matching_enabled' => true]);
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        $candidate = ReconciliationCandidate::query()->create([
            'reconciliation_session_id' => $session->id,
            'type' => 'ONE_TO_ONE', 'status' => 'PENDING', 'score' => 90, 'confidence' => 'HIGH',
            'engine_version' => 'rules-v1', 'signature_hash' => hash('sha256', 'candidate-'.$session->id),
            'evidence' => ['facts' => []], 'generated_by' => $this->operator->id, 'generated_at' => now(),
            'correlation_id' => (string) Str::uuid(),
        ]);

        $this->assertRule(
            fn () => app(ReconciliationCandidateService::class)->accept($session->id, $candidate->id, $this->operator->id, (string) Str::uuid()),
            'RECONCILIATION_SESSION_CLOSED',
        );
        $this->assertRule(
            fn () => app(ReconciliationCandidateService::class)->reject($session->id, $candidate->id, 'motivo', $this->operator->id, (string) Str::uuid()),
            'RECONCILIATION_SESSION_CLOSED',
        );
    }

    public function test_close_has_no_financial_or_legacy_side_effect(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $this->confirm($session, [[$title, null, '100.00']], [[$transaction, '100.00']]);
        $legacyBefore = $this->legacySnapshot();
        $titleBefore = $title->fresh()->getRawOriginal();
        $transactionBefore = $transaction->fresh()->getRawOriginal();

        app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, (string) Str::uuid());

        $this->assertSame($legacyBefore, $this->legacySnapshot());
        $this->assertSame($titleBefore, $title->fresh()->getRawOriginal());
        $this->assertSame($transactionBefore, $transaction->fresh()->getRawOriginal());
        $this->assertDatabaseCount('title_settlements', 0);
    }

    public function test_closure_migrations_never_touch_protected_legacy_tables(): void
    {
        $files = glob(database_path('migrations/2026_08_14_0000{10,20,30,40,50}_*.php'), GLOB_BRACE);
        $this->assertCount(5, $files);
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('Schema::table(', $contents);
            foreach (['lancamentos', 'recebimentos', 'movimentos', 'conciliacoes'] as $protected) {
                $this->assertDoesNotMatchRegularExpression("/['\"]{$protected}['\"]/", $contents);
            }
        }
    }

    public function test_web_closure_flow_prepare_confirm_history_and_reopen(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '250.00');
        $transaction = $this->transaction('DEBIT', '250.00');
        $this->confirm($session, [[$title, null, '250.00']], [[$transaction, '250.00']]);

        $this->actingAs($this->operator)
            ->get(route('reconciliation-v2.closure.create', $session))
            ->assertOk()
            ->assertSee('Preparar fechamento')
            ->assertSee('Confirmar fechamento definitivamente');

        $this->actingAs($this->operator)
            ->post(route('reconciliation-v2.closure.store', $session), ['confirm' => '1'])
            ->assertRedirect(route('reconciliation-v2.show', $session));
        $closure = ReconciliationClosure::query()->firstOrFail();

        $this->actingAs($this->operator)
            ->get(route('reconciliation-v2.closure.history', $session))
            ->assertOk()->assertSee('CLOSED');

        $this->actingAs($this->operator)
            ->get(route('reconciliation-v2.closure.show', [$session, $closure]))
            ->assertOk()->assertSee($closure->closure_hash);

        $this->actingAs($this->operator)
            ->post(route('reconciliation-v2.closure.reopen', [$session, $closure]), ['reason' => 'ajuste solicitado'])
            ->assertRedirect(route('reconciliation-v2.closure.show', [$session, $closure]));
        $this->assertSame('REOPENED', $session->fresh()->status->value);
    }

    private function reconciliationSession(int $accountId = 1, string $start = '2026-08-01', string $end = '2026-08-31'): ReconciliationSession
    {
        return app(ReconciliationSessionService::class)->create($accountId, $start, $end, $this->operator->id, (string) Str::uuid());
    }

    private function title(FinancialTitleType $type, string $amount, ?int $accountId = 1, string $dueDate = '2026-08-10'): FinancialTitle
    {
        $id = ++self::$sequence;

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: "CLOSURE-TITLE-{$id}", type: $type, issueDate: '2026-07-01',
            dueDate: $dueDate, originalAmount: $amount, partyName: "Parte {$id}", documentNumber: "DOC-{$id}",
            accountId: $accountId, installmentCount: 1,
        ), $this->operator->id)->title->load('installments');
    }

    private function transaction(string $direction, string $amount, int $accountId = 1, string $date = '2026-08-15'): BankTransaction
    {
        $id = ++self::$sequence;
        $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();
        $batch = ImportBatch::query()->create([
            'source_system_id' => $source->id, 'account_id' => $accountId, 'channel' => 'API', 'format' => 'CANONICAL_API',
            'status' => 'COMPLETED', 'total_items' => 1, 'imported_items' => 1, 'correlation_id' => (string) Str::uuid(),
            'started_at' => now(), 'completed_at' => now(),
        ]);

        return BankTransaction::query()->create([
            'account_id' => $accountId, 'source_system_id' => $source->id, 'import_batch_id' => $batch->id,
            'external_id' => "CLOSURE-TX-{$id}", 'identity_quality' => 'STRONG', 'direction' => $direction,
            'amount' => $amount, 'currency' => 'BRL', 'transaction_date' => $date,
            'description_original' => "Transação sintética {$id}", 'payload_hash' => hash('sha256', "payload-{$id}"),
            'raw_hash' => hash('sha256', "raw-{$id}"),
        ]);
    }

    /**
     * @param  list<array{FinancialTitle, ?TitleInstallment, string}>  $titles
     * @param  list<array{BankTransaction, string}>  $transactions
     */
    private function confirm(ReconciliationSession $session, array $titles, array $transactions, ?string $correlationId = null): ReconciliationMatch
    {
        return app(ManualReconciliationService::class)->confirm(
            $session->id,
            array_map(fn (array $item): ReconciliationTitleAllocationData => new ReconciliationTitleAllocationData($item[0]->id, $item[1]?->id, $item[2]), $titles),
            array_map(fn (array $item): ReconciliationTransactionAllocationData => new ReconciliationTransactionAllocationData($item[0]->id, $item[1]), $transactions),
            $this->operator->id,
            $correlationId ?? (string) Str::uuid(),
        );
    }

    private function exception(ReconciliationSession $session): ReconciliationException
    {
        return ReconciliationException::query()->create([
            'reconciliation_session_id' => $session->id, 'type' => 'NO_CANDIDATE', 'status' => 'OPEN',
            'evidence' => ['facts' => []], 'engine_version' => 'rules-v1',
            'signature_hash' => hash('sha256', 'exception-'.$session->id.'-'.Str::uuid()),
            'generated_by' => $this->operator->id, 'generated_at' => now(), 'correlation_id' => (string) Str::uuid(),
        ]);
    }

    /** @return array<string, array{count: int, marker: string}> */
    private function legacySnapshot(): array
    {
        $result = [];
        foreach (['lancamentos', 'recebimentos', 'movimentos'] as $table) {
            $result[$table] = ['count' => DB::table($table)->count(), 'marker' => (string) DB::table($table)->value('marker')];
        }
        $result['conciliacoes'] = ['count' => DB::table('conciliacoes')->count(), 'marker' => (string) DB::table('conciliacoes')->value('status')];

        return $result;
    }

    private function assertRule(callable $operation, string $rule): void
    {
        try {
            $operation();
            $this->fail("Era esperada a regra {$rule}.");
        } catch (ReconciliationRuleViolation $exception) {
            $this->assertSame($rule, $exception->rule);
        }
    }
}
