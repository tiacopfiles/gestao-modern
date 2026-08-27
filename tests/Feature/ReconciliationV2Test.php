<?php

namespace Tests\Feature;

use App\Application\Financial\TitleIngestionService;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationAllocationQuery;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\ImportBatch;
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

class ReconciliationV2Test extends TestCase
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

        $this->operator = User::query()->create([
            'nome' => 'Operador autorizado',
            'username' => 'operador',
            'password' => bcrypt('secret'),
        ]);
        config([
            'reconciliation.v2_enabled' => false,
            'reconciliation.view_user_ids' => [],
            'reconciliation.manage_user_ids' => [],
        ]);
    }

    public function test_feature_flag_is_off_by_default_and_legacy_reconciliation_remains_available(): void
    {
        config(['reconciliation.manage_user_ids' => [$this->operator->id]]);

        $this->actingAs($this->operator)->get('/reconciliacao-v2')->assertNotFound();
        $this->actingAs($this->operator)->get('/conciliacoes')->assertOk();
        $this->actingAs($this->operator)->get('/dashboard')->assertOk()->assertDontSee('Conciliação v2');
    }

    public function test_permissions_are_restrictive_and_separate_view_from_manage(): void
    {
        $this->enable();
        $this->actingAs($this->operator)->get('/reconciliacao-v2')->assertForbidden();

        config(['reconciliation.view_user_ids' => [$this->operator->id]]);
        $this->actingAs($this->operator)->get('/reconciliacao-v2')->assertOk();
        $this->actingAs($this->operator)->get('/reconciliacao-v2/nova')->assertForbidden();

        config(['reconciliation.manage_user_ids' => [$this->operator->id]]);
        $this->actingAs($this->operator)->get('/reconciliacao-v2/nova')->assertOk();
    }

    public function test_session_creation_validates_account_period_duplicate_actor_and_audit(): void
    {
        $this->enableManager();
        $service = app(ReconciliationSessionService::class);
        $session = $service->create(1, '2026-08-01', '2026-08-31', $this->operator->id, 'session-correlation');

        $this->assertSame('OPEN', $session->status->value);
        $this->assertSame($this->operator->id, $session->created_by);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'RECONCILIATION_SESSION_CREATED',
            'entity_id' => (string) $session->id,
            'actor_id' => $this->operator->id,
            'correlation_id' => 'session-correlation',
        ]);
        $this->assertRule(
            fn () => $service->create(1, '2026-08-01', '2026-08-31', $this->operator->id, 'duplicate'),
            'RECONCILIATION_SESSION_DUPLICATE',
        );
        $this->assertRule(fn () => $service->create(999, '2026-08-01', '2026-08-31', $this->operator->id, 'missing'), 'RECONCILIATION_ACCOUNT_NOT_FOUND');
        $this->assertRule(fn () => $service->create(1, '2026-08-31', '2026-08-01', $this->operator->id, 'invalid'), 'RECONCILIATION_INVALID_PERIOD');
        $this->assertRule(fn () => $service->create(2, '2026-08-01', '2026-08-31', 0, 'actor'), 'RECONCILIATION_ACTOR_REQUIRED');
    }

    public function test_web_session_contract_rejects_server_controlled_fields(): void
    {
        $this->enableManager();

        $this->actingAs($this->operator)->post('/reconciliacao-v2', [
            'account_id' => 1,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'created_by' => 999,
            'status' => 'IN_REVIEW',
            'correlation_id' => 'forged',
        ])->assertSessionHasErrors(['created_by', 'status', 'correlation_id']);
        $this->assertDatabaseCount('reconciliation_sessions', 0);
    }

    public function test_web_workspace_confirms_and_voids_a_persistent_manual_match(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '250.00');
        $installment = $title->installments->firstOrFail();
        $transaction = $this->transaction('DEBIT', '250.00');

        $this->actingAs($this->operator)
            ->get(route('reconciliation-v2.show', $session))
            ->assertOk()
            ->assertSee('Títulos e parcelas')
            ->assertSee('Transação sintética');

        $response = $this->actingAs($this->operator)
            ->post(route('reconciliation-v2.matches.store', $session), [
                'title_installment_ids' => [$installment->id],
                'title_allocations' => [(string) $installment->id => '250.00'],
                'bank_transaction_ids' => [$transaction->id],
                'transaction_allocations' => [(string) $transaction->id => '250.00'],
            ]);
        $match = ReconciliationMatch::query()->firstOrFail();
        $response->assertRedirect(route('reconciliation-v2.matches.show', [$session, $match]));

        $this->actingAs($this->operator)
            ->get(route('reconciliation-v2.matches.show', [$session, $match]))
            ->assertOk()
            ->assertSee("Match #{$match->id}")
            ->assertSee('250,00');

        $this->actingAs($this->operator)
            ->post(route('reconciliation-v2.matches.void', [$session, $match]), [
                'reason' => 'Revisão manual do operador',
            ])
            ->assertRedirect(route('reconciliation-v2.matches.show', [$session, $match]));
        $this->assertDatabaseHas('reconciliation_matches', [
            'id' => $match->id,
            'status' => 'VOIDED',
            'voided_by' => $this->operator->id,
            'void_reason' => 'Revisão manual do operador',
        ]);
    }

    public function test_one_to_one_is_persistent_audited_and_has_no_financial_or_legacy_side_effect(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '1000.00');
        $transaction = $this->transaction('DEBIT', '1000.00');
        $titleBefore = $title->fresh()->getRawOriginal();
        $transactionBefore = $transaction->fresh()->getRawOriginal();
        $legacyBefore = $this->legacySnapshot();

        $match = $this->confirm($session, [[$title, null, '1000.00']], [[$transaction, '1000.00']], 'one-to-one');

        $this->assertSame('CONFIRMED', $match->status->value);
        $this->assertSame('MANUAL', $match->method->value);
        $this->assertSame($this->operator->id, $match->confirmed_by);
        $this->assertDatabaseHas('reconciliation_match_titles', [
            'reconciliation_match_id' => $match->id,
            'financial_title_id' => $title->id,
            'title_installment_id' => $title->installments->first()->id,
            'allocated_amount' => '1000.00',
        ]);
        $this->assertDatabaseHas('reconciliation_match_transactions', [
            'reconciliation_match_id' => $match->id,
            'bank_transaction_id' => $transaction->id,
            'allocated_amount' => '1000.00',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'RECONCILIATION_MATCH_CONFIRMED',
            'entity_id' => (string) $match->id,
            'actor_id' => $this->operator->id,
            'correlation_id' => 'one-to-one',
        ]);
        $this->assertSame($titleBefore, $title->fresh()->getRawOriginal());
        $this->assertSame($transactionBefore, $transaction->fresh()->getRawOriginal());
        $this->assertDatabaseCount('title_settlements', 0);
        $this->assertSame($legacyBefore, $this->legacySnapshot());
    }

    public function test_one_title_to_multiple_transactions_is_supported(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '1000.00');
        $a = $this->transaction('DEBIT', '600.00');
        $b = $this->transaction('DEBIT', '400.00');

        $match = $this->confirm($session, [[$title, null, '1000.00']], [[$a, '600.00'], [$b, '400.00']]);

        $this->assertCount(1, $match->titleAllocations);
        $this->assertCount(2, $match->transactionAllocations);
    }

    public function test_multiple_titles_to_one_transaction_is_supported(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $a = $this->title(FinancialTitleType::Payable, '400.00');
        $b = $this->title(FinancialTitleType::Payable, '600.00');
        $transaction = $this->transaction('DEBIT', '1000.00');

        $match = $this->confirm($session, [[$a, null, '400.00'], [$b, null, '600.00']], [[$transaction, '1000.00']]);

        $this->assertCount(2, $match->titleAllocations);
        $this->assertCount(1, $match->transactionAllocations);
    }

    public function test_multiple_titles_to_multiple_transactions_is_supported(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $titleA = $this->title(FinancialTitleType::Payable, '300.00');
        $titleB = $this->title(FinancialTitleType::Payable, '700.00');
        $transactionA = $this->transaction('DEBIT', '500.00');
        $transactionB = $this->transaction('DEBIT', '500.00');

        $match = $this->confirm(
            $session,
            [[$titleA, null, '300.00'], [$titleB, null, '700.00']],
            [[$transactionA, '500.00'], [$transactionB, '500.00']],
        );

        $this->assertCount(2, $match->titleAllocations);
        $this->assertCount(2, $match->transactionAllocations);
    }

    public function test_partial_match_derives_available_values_and_blocks_title_over_allocation(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '1000.00');
        $transaction = $this->transaction('DEBIT', '600.00');
        $this->confirm($session, [[$title, null, '600.00']], [[$transaction, '600.00']]);
        $installment = $title->installments->first();
        $query = app(ReconciliationAllocationQuery::class);

        $this->assertSame(60000, $query->confirmedTitleCents([$installment->id])[$installment->id]);
        $this->assertSame(60000, $query->confirmedTransactionCents([$transaction->id])[$transaction->id]);

        $anotherTransaction = $this->transaction('DEBIT', '500.00');
        $this->assertRule(
            fn () => $this->confirm($session, [[$title, null, '500.00']], [[$anotherTransaction, '500.00']]),
            'RECONCILIATION_TITLE_OVER_ALLOCATED',
        );
        $this->assertDatabaseCount('reconciliation_matches', 1);
    }

    public function test_transaction_over_allocation_is_blocked_as_concurrency_defense(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $transaction = $this->transaction('DEBIT', '1000.00');
        $first = $this->title(FinancialTitleType::Payable, '700.00');
        $second = $this->title(FinancialTitleType::Payable, '500.00');
        $this->confirm($session, [[$first, null, '700.00']], [[$transaction, '700.00']]);

        $this->assertRule(
            fn () => $this->confirm($session, [[$second, null, '500.00']], [[$transaction, '500.00']]),
            'RECONCILIATION_TRANSACTION_OVER_ALLOCATED',
        );
        $this->assertDatabaseCount('reconciliation_matches', 1);
    }

    public function test_unbalanced_match_is_rejected_atomically(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '1000.00');
        $transaction = $this->transaction('DEBIT', '990.00');

        $this->assertRule(
            fn () => $this->confirm($session, [[$title, null, '1000.00']], [[$transaction, '990.00']]),
            'RECONCILIATION_UNBALANCED',
        );
        $this->assertDatabaseCount('reconciliation_matches', 0);
        $this->assertDatabaseCount('reconciliation_match_titles', 0);
        $this->assertDatabaseCount('reconciliation_match_transactions', 0);
    }

    public function test_payable_credit_and_receivable_debit_are_rejected(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $payable = $this->title(FinancialTitleType::Payable, '100.00');
        $credit = $this->transaction('CREDIT', '100.00');
        $this->assertRule(fn () => $this->confirm($session, [[$payable, null, '100.00']], [[$credit, '100.00']]), 'RECONCILIATION_DIRECTION_MISMATCH');

        $receivable = $this->title(FinancialTitleType::Receivable, '100.00');
        $debit = $this->transaction('DEBIT', '100.00');
        $this->assertRule(fn () => $this->confirm($session, [[$receivable, null, '100.00']], [[$debit, '100.00']]), 'RECONCILIATION_DIRECTION_MISMATCH');
    }

    public function test_mixed_title_types_and_bank_directions_are_rejected(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $payable = $this->title(FinancialTitleType::Payable, '50.00');
        $receivable = $this->title(FinancialTitleType::Receivable, '50.00');
        $debit = $this->transaction('DEBIT', '50.00');
        $credit = $this->transaction('CREDIT', '50.00');

        $this->assertRule(
            fn () => $this->confirm($session, [[$payable, null, '50.00'], [$receivable, null, '50.00']], [[$debit, '50.00'], [$credit, '50.00']]),
            'RECONCILIATION_MIXED_DIRECTIONS',
        );
    }

    public function test_title_and_transaction_account_mismatches_are_rejected(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $wrongTitle = $this->title(FinancialTitleType::Payable, '100.00', 2);
        $rightTransaction = $this->transaction('DEBIT', '100.00');
        $this->assertRule(fn () => $this->confirm($session, [[$wrongTitle, null, '100.00']], [[$rightTransaction, '100.00']]), 'RECONCILIATION_ACCOUNT_MISMATCH');

        $rightTitle = $this->title(FinancialTitleType::Payable, '100.00');
        $wrongTransaction = $this->transaction('DEBIT', '100.00', 2);
        $this->assertRule(fn () => $this->confirm($session, [[$rightTitle, null, '100.00']], [[$wrongTransaction, '100.00']]), 'RECONCILIATION_ACCOUNT_MISMATCH');
    }

    public function test_transaction_period_is_enforced_but_old_title_is_allowed(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $oldTitle = $this->title(FinancialTitleType::Payable, '100.00', 1, '2026-07-10');
        $outside = $this->transaction('DEBIT', '100.00', 1, '2026-09-01');
        $this->assertRule(fn () => $this->confirm($session, [[$oldTitle, null, '100.00']], [[$outside, '100.00']]), 'RECONCILIATION_TRANSACTION_OUTSIDE_PERIOD');

        $inside = $this->transaction('DEBIT', '100.00');
        $match = $this->confirm($session, [[$oldTitle, null, '100.00']], [[$inside, '100.00']]);
        $this->assertSame('CONFIRMED', $match->status->value);
    }

    public function test_null_account_cancelled_title_and_ambiguous_installment_are_blocked(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $transaction = $this->transaction('DEBIT', '100.00');
        $withoutAccount = $this->title(FinancialTitleType::Payable, '100.00', null);
        $this->assertRule(fn () => $this->confirm($session, [[$withoutAccount, null, '100.00']], [[$transaction, '100.00']]), 'RECONCILIATION_TITLE_ACCOUNT_REQUIRED');

        $cancelled = $this->title(FinancialTitleType::Payable, '100.00');
        $cancelled->update(['status' => 'CANCELLED']);
        $this->assertRule(fn () => $this->confirm($session, [[$cancelled, null, '100.00']], [[$transaction, '100.00']]), 'RECONCILIATION_TITLE_CANCELLED');

        $parcelled = $this->title(FinancialTitleType::Payable, '100.00', 1, '2026-07-10', 2);
        $this->assertRule(fn () => $this->confirm($session, [[$parcelled, null, '100.00']], [[$transaction, '100.00']]), 'RECONCILIATION_INSTALLMENT_REQUIRED');
    }

    public function test_explicit_installment_allows_partial_reconciliation_of_parcelled_title(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '1000.00', 1, '2026-07-10', 2);
        $transaction = $this->transaction('DEBIT', '500.00');
        $installment = $title->installments->first();

        $match = $this->confirm($session, [[$title, $installment, '500.00']], [[$transaction, '500.00']]);

        $this->assertDatabaseHas('reconciliation_match_titles', [
            'reconciliation_match_id' => $match->id,
            'title_installment_id' => $installment->id,
            'allocated_amount' => '500.00',
        ]);
    }

    public function test_void_preserves_history_releases_allocations_and_records_actor_reason_and_audit(): void
    {
        $this->enableManager();
        $session = $this->reconciliationSession();
        $title = $this->title(FinancialTitleType::Payable, '1000.00');
        $transaction = $this->transaction('DEBIT', '1000.00');
        $transactionBefore = $this->bankTransactionSnapshot($transaction);
        $match = $this->confirm($session, [[$title, null, '1000.00']], [[$transaction, '1000.00']], 'confirm-correlation');

        $voided = app(ManualReconciliationService::class)->void(
            $session->id,
            $match->id,
            'Conciliação selecionada incorretamente',
            $this->operator->id,
            'void-correlation',
        );

        $this->assertSame('VOIDED', $voided->status->value);
        $this->assertSame($this->operator->id, $voided->voided_by);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('Conciliação selecionada incorretamente', $voided->void_reason);
        $this->assertDatabaseCount('reconciliation_matches', 1);
        $this->assertDatabaseCount('reconciliation_match_titles', 1);
        $this->assertDatabaseCount('reconciliation_match_transactions', 1);
        $this->assertSame([], app(ReconciliationAllocationQuery::class)->confirmedTransactionCents([$transaction->id]));
        $this->assertSame($transactionBefore, $this->bankTransactionSnapshot($transaction));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'RECONCILIATION_MATCH_VOIDED',
            'entity_id' => (string) $match->id,
            'actor_id' => $this->operator->id,
            'correlation_id' => 'void-correlation',
        ]);

        $replacement = $this->confirm($session, [[$title, null, '1000.00']], [[$transaction, '1000.00']]);
        $this->assertNotSame($match->id, $replacement->id);
    }

    public function test_match_idor_is_blocked_by_nested_session_and_permission(): void
    {
        $this->enableManager();
        $firstSession = $this->reconciliationSession();
        $secondSession = app(ReconciliationSessionService::class)->create(1, '2026-09-01', '2026-09-30', $this->operator->id, 'second');
        $title = $this->title(FinancialTitleType::Payable, '100.00');
        $transaction = $this->transaction('DEBIT', '100.00');
        $match = $this->confirm($firstSession, [[$title, null, '100.00']], [[$transaction, '100.00']]);

        $this->actingAs($this->operator)
            ->get("/reconciliacao-v2/sessoes/{$secondSession->id}/matches/{$match->id}")
            ->assertNotFound();
        $this->assertRule(
            fn () => app(ManualReconciliationService::class)->void($secondSession->id, $match->id, 'Teste IDOR', $this->operator->id, 'idor'),
            'RECONCILIATION_MATCH_NOT_FOUND',
        );

        config(['reconciliation.view_user_ids' => [], 'reconciliation.manage_user_ids' => []]);
        $this->actingAs($this->operator)
            ->get("/reconciliacao-v2/sessoes/{$firstSession->id}/matches/{$match->id}")
            ->assertForbidden();
    }

    public function test_phase_four_migrations_are_additive_and_do_not_reference_protected_tables(): void
    {
        $files = glob(database_path('migrations/2026_08_13_0001{30,40,50,60}_*.php'), GLOB_BRACE);
        $this->assertCount(4, $files);
        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringNotContainsString('Schema::table(', $contents);
            foreach (['lancamentos', 'recebimentos', 'movimentos', 'conciliacoes'] as $protected) {
                $this->assertDoesNotMatchRegularExpression("/['\"]{$protected}['\"]/", $contents);
            }
        }
    }

    public function test_api_exposes_only_titles_banking_and_settlement_never_reconciliation(): void
    {
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $api = $routes->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'));

        // 15 = 13 operações das Fases 1–3 + 2 de liquidação, acrescentadas para
        // que Contas a Pagar / Contas a Receber possam informar a realização.
        $this->assertCount(15, $api);
        $this->assertCount(2, $api->filter(fn ($route): bool => str_ends_with($route->uri(), '/settlements')));
        // A regra que realmente importa: conciliação e fechamento continuam fora
        // da API v1 — são fluxo humano auditável, não integração automática.
        $this->assertFalse($api->contains(fn ($route): bool => str_contains($route->uri(), 'reconciliation')));
        $this->assertFalse($api->contains(fn ($route): bool => str_contains($route->uri(), 'closure')));
        // 13 rotas de Fases 4/5 + 5 rotas de fechamento/reabertura da Fase 6 (closure.*).
        $this->assertCount(18, $routes->filter(fn ($route): bool => str_starts_with($route->uri(), 'reconciliacao-v2')));
    }

    private function enable(): void
    {
        config(['reconciliation.v2_enabled' => true]);
    }

    private function enableManager(): void
    {
        $this->enable();
        config([
            'reconciliation.view_user_ids' => [$this->operator->id],
            'reconciliation.manage_user_ids' => [$this->operator->id],
        ]);
    }

    private function reconciliationSession(int $accountId = 1): ReconciliationSession
    {
        return app(ReconciliationSessionService::class)->create(
            $accountId,
            '2026-08-01',
            '2026-08-31',
            $this->operator->id,
            (string) Str::uuid(),
        );
    }

    private function title(
        FinancialTitleType $type,
        string $amount,
        ?int $accountId = 1,
        string $dueDate = '2026-07-10',
        int $installments = 1,
    ): FinancialTitle {
        $id = ++self::$sequence;

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API',
            externalId: "RECON-TITLE-{$id}",
            type: $type,
            issueDate: '2026-06-01',
            dueDate: $dueDate,
            originalAmount: $amount,
            partyName: "Parte {$id}",
            documentNumber: "DOC-{$id}",
            accountId: $accountId,
            installmentCount: $installments,
        ), $this->operator->id)->title->load('installments');
    }

    private function transaction(
        string $direction,
        string $amount,
        int $accountId = 1,
        string $date = '2026-08-15',
    ): BankTransaction {
        $id = ++self::$sequence;
        $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();
        $batch = ImportBatch::query()->create([
            'source_system_id' => $source->id,
            'account_id' => $accountId,
            'channel' => 'API',
            'format' => 'CANONICAL_API',
            'status' => 'COMPLETED',
            'total_items' => 1,
            'imported_items' => 1,
            'correlation_id' => (string) Str::uuid(),
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        return BankTransaction::query()->create([
            'account_id' => $accountId,
            'source_system_id' => $source->id,
            'import_batch_id' => $batch->id,
            'external_id' => "RECON-TX-{$id}",
            'identity_quality' => 'STRONG',
            'direction' => $direction,
            'amount' => $amount,
            'currency' => 'BRL',
            'transaction_date' => $date,
            'description_original' => "Transação sintética {$id}",
            'payload_hash' => hash('sha256', "payload-{$id}"),
            'raw_hash' => hash('sha256', "raw-{$id}"),
        ]);
    }

    /**
     * @param  list<array{FinancialTitle, ?TitleInstallment, string}>  $titles
     * @param  list<array{BankTransaction, string}>  $transactions
     */
    private function confirm(
        ReconciliationSession $session,
        array $titles,
        array $transactions,
        ?string $correlationId = null,
    ): ReconciliationMatch {
        return app(ManualReconciliationService::class)->confirm(
            $session->id,
            array_map(fn (array $item): ReconciliationTitleAllocationData => new ReconciliationTitleAllocationData(
                $item[0]->id,
                $item[1]?->id,
                $item[2],
            ), $titles),
            array_map(fn (array $item): ReconciliationTransactionAllocationData => new ReconciliationTransactionAllocationData(
                $item[0]->id,
                $item[1],
            ), $transactions),
            $this->operator->id,
            $correlationId ?? (string) Str::uuid(),
        );
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

    /** @return array<string, int|string|null> */
    private function bankTransactionSnapshot(BankTransaction $transaction): array
    {
        $transaction = $transaction->fresh() ?? $transaction;

        return [
            'account_id' => (int) $transaction->account_id,
            'source_system_id' => (int) $transaction->source_system_id,
            'import_batch_id' => (int) $transaction->import_batch_id,
            'external_id' => $transaction->external_id,
            'identity_quality' => $transaction->identity_quality,
            'direction' => $transaction->direction->value,
            'amount' => (string) $transaction->amount,
            'currency' => $transaction->currency,
            'transaction_date' => $transaction->transaction_date->toDateString(),
            'description_original' => $transaction->description_original,
            'payload_hash' => $transaction->getRawOriginal('payload_hash'),
            'raw_hash' => $transaction->getRawOriginal('raw_hash'),
        ];
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
