<?php

namespace Tests\Feature;

use App\Application\Financial\TitleIngestionService;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationCandidateService;
use App\Application\Reconciliation\ReconciliationExceptionService;
use App\Application\Reconciliation\ReconciliationMatchingEngine;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\ImportBatch;
use App\Models\ReconciliationCandidate;
use App\Models\ReconciliationException;
use App\Models\ReconciliationSession;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

class ReconciliationMatchingTest extends TestCase
{
    use RefreshesTestDatabase;

    private User $operator;

    private static int $sequence = 1000;

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
        DB::table('contas')->insert([['id' => 1, 'nome' => 'Banco A', 'created_at' => now(), 'updated_at' => now()]]);
        $this->operator = User::query()->create(['nome' => 'Operador', 'username' => 'matching', 'password' => bcrypt('secret')]);
        config([
            'reconciliation.v2_enabled' => true, 'reconciliation.matching_enabled' => true,
            'reconciliation.view_user_ids' => [$this->operator->id], 'reconciliation.manage_user_ids' => [$this->operator->id],
        ]);
    }

    public function test_independent_kill_switch_hides_matching_but_preserves_manual_workspace(): void
    {
        $session = $this->reconciliationSession();
        config(['reconciliation.matching_enabled' => false]);
        $this->actingAs($this->operator)->get(route('reconciliation-v2.show', $session))->assertOk()->assertDontSee('Matching determinístico');
        $this->actingAs($this->operator)->post("/reconciliacao-v2/sessoes/{$session->id}/matching/gerar")->assertNotFound();
    }

    public function test_generation_is_deterministic_idempotent_explainable_and_has_no_financial_effect(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title('1000.00', 'DOC-42', 'Fazenda São José');
        $transaction = $this->transaction('1000.00', 'DOC42', 'FAZENDA SAO JOSE');
        $titleBefore = $title->fresh()->getRawOriginal();
        $bankBefore = $transaction->fresh()->getRawOriginal();

        $first = $this->generate($session, 'generation-one');
        $candidate = ReconciliationCandidate::query()->firstOrFail();
        $this->assertSame(1, $first['candidates']);
        $this->assertGreaterThanOrEqual(75, $candidate->score);
        $this->assertContains('BUSINESS_DOCUMENT_EXACT', collect($candidate->evidence['signals'])->pluck('code')->all());
        $this->generate($session, 'generation-two');
        $this->assertDatabaseCount('reconciliation_candidates', 1);
        $this->assertDatabaseCount('reconciliation_matches', 0);
        $this->assertDatabaseCount('title_settlements', 0);
        $this->assertSame($titleBefore, $title->fresh()->getRawOriginal());
        $this->assertSame($bankBefore, $transaction->fresh()->getRawOriginal());
    }

    public function test_limited_one_to_many_and_many_to_one_are_generated_without_many_to_many(): void
    {
        $first = $this->reconciliationSession();
        $this->title('1000.00');
        $this->transaction('600.00');
        $this->transaction('400.00');
        $this->generate($first);
        $this->assertDatabaseHas('reconciliation_candidates', ['type' => 'ONE_TO_MANY', 'status' => 'PENDING']);

        $second = app(ReconciliationSessionService::class)->create(1, '2026-09-01', '2026-09-30', $this->operator->id, 'second-session');
        $this->title('300.00', null, null, '2026-09-15');
        $this->title('700.00', null, null, '2026-09-15');
        $this->transaction('1000.00', null, null, '2026-09-15');
        $this->generate($second);
        $this->assertDatabaseHas('reconciliation_candidates', ['reconciliation_session_id' => $second->id, 'type' => 'MANY_TO_ONE']);
        $this->assertDatabaseMissing('reconciliation_candidates', ['type' => 'MANY_TO_MANY']);
    }

    public function test_ambiguity_no_candidate_and_strong_document_amount_mismatch_enter_exception_queue(): void
    {
        $session = $this->reconciliationSession();
        $this->title('100.00');
        $this->title('100.00');
        $this->transaction('100.00');
        $this->title('150.00', 'SPECIAL-9');
        $this->transaction('125.00', 'SPECIAL9');
        $this->transaction('987.65', 'NO-LINK', 'Parte desconhecida');
        $this->generate($session);
        $this->assertDatabaseHas('reconciliation_exceptions', ['type' => 'AMBIGUOUS_CANDIDATES', 'status' => 'OPEN']);
        $this->assertDatabaseHas('reconciliation_exceptions', ['type' => 'AMOUNT_MISMATCH', 'difference_amount' => '25.00']);
        $this->assertDatabaseHas('reconciliation_exceptions', ['type' => 'NO_CANDIDATE']);
    }

    public function test_explicit_acceptance_revalidates_through_manual_service_and_resolves_related_exception(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title('200.00');
        $transaction = $this->transaction('200.00');
        $this->generate($session);
        $candidate = ReconciliationCandidate::query()->firstOrFail();
        ReconciliationException::query()->create([
            'reconciliation_session_id' => $session->id, 'bank_transaction_id' => $transaction->id, 'type' => 'AMBIGUOUS_CANDIDATES', 'status' => 'OPEN',
            'evidence' => [], 'engine_version' => 'test', 'signature_hash' => hash('sha256', 'related'), 'generated_by' => $this->operator->id,
            'generated_at' => now(), 'correlation_id' => (string) Str::uuid(),
        ]);
        $match = app(ReconciliationCandidateService::class)->accept($session->id, $candidate->id, $this->operator->id, 'candidate-accept');
        $this->assertSame('CONFIRMED', $match->status->value);
        $this->assertSame('MANUAL', $match->method->value);
        $this->assertDatabaseHas('reconciliation_candidates', ['id' => $candidate->id, 'status' => 'ACCEPTED', 'reconciliation_match_id' => $match->id]);
        $this->assertDatabaseHas('reconciliation_exceptions', ['signature_hash' => hash('sha256', 'related'), 'status' => 'RESOLVED']);
        $this->assertSame('OPEN', $title->fresh()->status->value);
        $this->assertSame('200.00', $transaction->fresh()->amount);
    }

    public function test_stale_candidate_is_preserved_when_availability_changes(): void
    {
        $session = $this->reconciliationSession();
        $title = $this->title('300.00');
        $transaction = $this->transaction('300.00');
        $this->generate($session);
        $candidate = ReconciliationCandidate::query()->firstOrFail();
        app(ManualReconciliationService::class)->confirm($session->id, [new ReconciliationTitleAllocationData($title->id, $title->installments->first()->id, '300.00')], [new ReconciliationTransactionAllocationData($transaction->id, '300.00')], $this->operator->id, 'competing-match');
        try {
            app(ReconciliationCandidateService::class)->accept($session->id, $candidate->id, $this->operator->id, 'stale-accept');
        } catch (\Throwable) {
        }
        $this->assertDatabaseHas('reconciliation_candidates', ['id' => $candidate->id, 'status' => 'STALE']);
        $this->assertDatabaseCount('reconciliation_matches', 1);
    }

    public function test_rejection_and_justification_require_reasons_and_preserve_history(): void
    {
        $session = $this->reconciliationSession();
        $this->title('400.00');
        $this->transaction('400.00');
        $this->generate($session);
        $candidate = ReconciliationCandidate::query()->firstOrFail();
        app(ReconciliationCandidateService::class)->reject($session->id, $candidate->id, 'Não corresponde ao comprovante', $this->operator->id, 'reject');
        $exception = ReconciliationException::query()->create([
            'reconciliation_session_id' => $session->id, 'type' => 'MISSING_REQUIRED_DATA', 'status' => 'OPEN', 'evidence' => [],
            'engine_version' => 'test', 'signature_hash' => hash('sha256', 'justify'), 'generated_by' => $this->operator->id, 'generated_at' => now(), 'correlation_id' => (string) Str::uuid(),
        ]);
        app(ReconciliationExceptionService::class)->justify($session->id, $exception->id, 'Documento verificado externamente', $this->operator->id, 'justify');
        $this->assertDatabaseHas('reconciliation_candidates', ['id' => $candidate->id, 'status' => 'REJECTED', 'decision_reason' => 'Não corresponde ao comprovante']);
        $this->assertDatabaseHas('reconciliation_exceptions', ['id' => $exception->id, 'status' => 'JUSTIFIED', 'resolution_reason' => 'Documento verificado externamente']);
        $this->assertDatabaseCount('reconciliation_matches', 0);
    }

    public function test_synthetic_volume_is_bounded_before_persistence(): void
    {
        $session = $this->reconciliationSession();
        for ($i = 0; $i < 20; $i++) {
            $this->title('50.00', "VOL-T-{$i}");
            $this->transaction('50.00', "VOL-B-{$i}");
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->generate($session, 'bounded-volume');

        $this->assertLessThanOrEqual(160, ReconciliationCandidate::query()->count());
        $this->assertLessThan(1000, $queries);
        $this->assertDatabaseCount('reconciliation_matches', 0);
    }

    public function test_phase_five_migrations_and_routes_are_additive_and_api_is_unchanged(): void
    {
        $files = glob(database_path('migrations/2026_08_13_000{170,180,190,200}_*.php'), GLOB_BRACE);
        $this->assertCount(4, $files);
        foreach ($files as $file) {
            foreach (['lancamentos', 'recebimentos', 'movimentos', 'conciliacoes'] as $protected) {
                $this->assertDoesNotMatchRegularExpression("/['\"]{$protected}['\"]/", (string) file_get_contents($file));
            }
        }
        $routes = collect(app('router')->getRoutes()->getRoutes());
        $api = $routes->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/'));
        // 15 = 13 operações das Fases 1–3 + 2 de liquidação (payables/receivables),
        // acrescentadas para a origem informar pagamento/recebimento. O que esta
        // asserção protege não é o número em si: é que o matching (Fase 5) nunca
        // vaze para a API v1.
        $this->assertCount(15, $api);
        $this->assertFalse($api->contains(fn ($route): bool => str_contains($route->uri(), 'matching')));
    }

    private function reconciliationSession(): ReconciliationSession
    {
        return app(ReconciliationSessionService::class)->create(1, '2026-08-01', '2026-08-31', $this->operator->id, (string) Str::uuid());
    }

    private function title(string $amount, ?string $document = null, ?string $party = null, string $dueDate = '2026-08-15'): FinancialTitle
    {
        $id = ++self::$sequence;

        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: "MATCH-TITLE-{$id}", type: FinancialTitleType::Payable,
            issueDate: '2026-08-01', dueDate: $dueDate, originalAmount: $amount,
            partyName: $party ?? "Parte {$id}", documentNumber: $document ?? "DOC-{$id}", accountId: 1,
        ), $this->operator->id)->title->load('installments');
    }

    private function transaction(string $amount, ?string $document = null, ?string $party = null, string $date = '2026-08-15'): BankTransaction
    {
        $id = ++self::$sequence;
        $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();
        $batch = ImportBatch::query()->create(['source_system_id' => $source->id, 'account_id' => 1, 'channel' => 'API', 'format' => 'CANONICAL_API', 'status' => 'COMPLETED', 'total_items' => 1, 'imported_items' => 1, 'correlation_id' => (string) Str::uuid(), 'started_at' => now(), 'completed_at' => now()]);

        return BankTransaction::query()->create([
            'account_id' => 1, 'source_system_id' => $source->id, 'import_batch_id' => $batch->id, 'external_id' => "MATCH-TX-{$id}",
            'identity_quality' => 'STRONG', 'direction' => 'DEBIT', 'amount' => $amount, 'currency' => 'BRL', 'transaction_date' => $date,
            'description_original' => ($party ?? "Parte {$id}").' '.($document ?? ''), 'document_number' => $document,
            'counterparty_name' => $party, 'payload_hash' => hash('sha256', "payload-{$id}"), 'raw_hash' => hash('sha256', "raw-{$id}"),
        ]);
    }

    private function generate(ReconciliationSession $session, string $correlation = 'matching-generation'): array
    {
        return app(ReconciliationMatchingEngine::class)->generate($session->id, $this->operator->id, $correlation);
    }
}
