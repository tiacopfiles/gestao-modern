<?php

namespace Tests\Homologation;

use App\Application\Financial\TitleIngestionService;
use App\Application\Integration\IntegrationCredentialService;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationClosureService;
use App\Application\Reconciliation\ReconciliationMatchingEngine;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Money;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\ReconciliationCandidate;
use App\Models\ReconciliationMatch;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

#[Group('mariadb')]
class MariaDbConcurrencyHomologationTest extends TestCase
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
        $this->operator = User::query()->create(['nome' => 'Operador sintético', 'username' => 'hml', 'password' => bcrypt('synthetic')]);
        config(['reconciliation.v2_enabled' => true, 'reconciliation.matching_enabled' => true]);
    }

    public function test_parallel_http_idempotency_and_external_identity_create_one_title(): void
    {
        $issued = app(IntegrationCredentialService::class)->issue('API', 'HML API', ['payables:write']);
        $base = ['mode' => 'http_json', 'method' => 'POST', 'uri' => '/api/v1/payables', 'token' => $issued->plainTextToken, 'payload' => $this->titlePayload('HML-PARALLEL-1')];
        $sameKey = $this->parallel(
            $base + ['idempotency_key' => 'same-key', 'correlation_id' => 'same-key-a'],
            $base + ['idempotency_key' => 'same-key', 'correlation_id' => 'same-key-b'],
        );
        $this->assertStatuses([201, 201], $sameKey);
        $this->assertSame(['false', 'true'], collect($sameKey)->pluck('replayed')->sort()->values()->all());
        $this->assertDatabaseCount('financial_titles', 1);
        $this->assertDatabaseCount('integration_requests', 1);

        $differentKeys = $this->parallel(
            $base + ['idempotency_key' => 'identity-a', 'correlation_id' => 'identity-a'],
            $base + ['idempotency_key' => 'identity-b', 'correlation_id' => 'identity-b'],
        );
        $this->assertStatuses([200, 200], $differentKeys);
        $this->assertDatabaseCount('financial_titles', 1);
    }

    public function test_parallel_same_key_with_different_payload_conflicts_without_corruption(): void
    {
        $issued = app(IntegrationCredentialService::class)->issue('API', 'HML API conflict', ['payables:write']);
        $base = ['mode' => 'http_json', 'method' => 'POST', 'uri' => '/api/v1/payables', 'token' => $issued->plainTextToken, 'idempotency_key' => 'conflicting-key'];
        $a = $this->titlePayload('HML-CONFLICT');
        $b = $a;
        $b['original_amount'] = '101.00';
        $results = $this->parallel(
            $base + ['payload' => $a, 'correlation_id' => 'conflict-a'],
            $base + ['payload' => $b, 'correlation_id' => 'conflict-b'],
        );
        $this->assertSame([201, 409], $this->statuses($results));
        $this->assertDatabaseCount('financial_titles', 1);
        $this->assertDatabaseCount('integration_requests', 1);
    }

    public function test_parallel_bank_identity_deduplicates_but_legitimate_equal_facts_survive(): void
    {
        $issued = app(IntegrationCredentialService::class)->issue('BANK_IMPORT', 'HML Bank', ['bank-transactions:write']);
        $base = ['mode' => 'http_json', 'method' => 'POST', 'uri' => '/api/v1/bank-transactions', 'token' => $issued->plainTextToken];
        $payload = $this->bankPayload('BANK-SAME-ID');
        $duplicate = $this->parallel(
            $base + ['payload' => $payload, 'idempotency_key' => 'bank-a', 'correlation_id' => 'bank-a'],
            $base + ['payload' => $payload, 'idempotency_key' => 'bank-b', 'correlation_id' => 'bank-b'],
        );
        $this->assertStatuses([200, 201], $duplicate);
        $this->assertDatabaseCount('bank_transactions', 1);

        $a = $this->bankPayload('BANK-LEGIT-A');
        $b = $this->bankPayload('BANK-LEGIT-B');
        $legitimate = $this->parallel(
            $base + ['payload' => $a, 'idempotency_key' => 'legit-a', 'correlation_id' => 'legit-a'],
            $base + ['payload' => $b, 'idempotency_key' => 'legit-b', 'correlation_id' => 'legit-b'],
        );
        $this->assertStatuses([201, 201], $legitimate);
        $this->assertDatabaseCount('bank_transactions', 3);
    }

    public function test_parallel_ofx_import_does_not_duplicate_fitids(): void
    {
        $issued = app(IntegrationCredentialService::class)->issue('BANK_IMPORT', 'HML OFX', ['bank-imports:write']);
        $fixture = base_path('tests/Fixtures/Banking/statement-valid.ofx');
        $base = ['mode' => 'http_ofx', 'method' => 'POST', 'uri' => '/api/v1/bank-imports/ofx', 'token' => $issued->plainTextToken, 'account_id' => 1, 'file' => $fixture];
        $results = $this->parallel(
            $base + ['idempotency_key' => 'ofx-a', 'correlation_id' => 'ofx-a'],
            $base + ['idempotency_key' => 'ofx-b', 'correlation_id' => 'ofx-b'],
        );
        // Um dos workers importa (201) e o outro reconhece o arquivo como
        // duplicado (200). O resultado correto é exatamente uma linha por FITID
        // do extrato — nunca o dobro. O total sai da própria fixture para que a
        // asserção não dependa de uma constante que possa divergir dela.
        $expectedTransactions = preg_match_all('/<FITID>/', (string) file_get_contents($fixture));

        $this->assertStatuses([200, 201], $results);
        $this->assertSame($expectedTransactions, BankTransaction::query()->count());
        $this->assertSame(1, ImportBatch::query()->where('format', 'OFX')->count());
    }

    public function test_parallel_manual_confirmation_prevents_full_and_partial_over_allocation(): void
    {
        foreach (['1000.00', '600.00'] as $amount) {
            $session = $this->createReconciliationSession($amount === '1000.00' ? '2026-08-01' : '2026-09-01', $amount === '1000.00' ? '2026-08-31' : '2026-09-30');
            $title = $this->title('1000.00', 'TITLE-'.$amount, $amount === '1000.00' ? '2026-08-15' : '2026-09-15');
            $transaction = $this->transaction('1000.00', 'TX-'.$amount, $amount === '1000.00' ? '2026-08-15' : '2026-09-15');
            $base = [
                'mode' => 'manual_confirm', 'session_id' => $session->id, 'title_id' => $title->id,
                'installment_id' => $title->installments->first()->id, 'transaction_id' => $transaction->id,
                'amount' => $amount, 'actor_id' => $this->operator->id,
            ];
            $results = $this->parallel($base + ['correlation_id' => 'match-a-'.$amount], $base + ['correlation_id' => 'match-b-'.$amount]);
            $this->assertSame(1, collect($results)->where('outcome', 'MATCH')->count());
            $this->assertSame(1, collect($results)->where('outcome', 'RULE')->count());
        }
        $this->assertDatabaseCount('reconciliation_matches', 2);
        $allocated = DB::table('reconciliation_match_transactions')->sum('allocated_amount');
        $this->assertSame(160000, Money::toCents((string) $allocated));
    }

    public function test_parallel_candidate_acceptance_creates_one_match_and_stale_revalidation_is_safe(): void
    {
        $session = $this->createReconciliationSession('2026-08-01', '2026-08-31');
        $title = $this->title('250.00', 'CANDIDATE-TITLE', '2026-08-15');
        $transaction = $this->transaction('250.00', 'CANDIDATE-TX', '2026-08-15');
        app(ReconciliationMatchingEngine::class)->generate($session->id, $this->operator->id, 'generate-candidate');
        $candidate = ReconciliationCandidate::query()->firstOrFail();
        $base = ['mode' => 'candidate_accept', 'session_id' => $session->id, 'candidate_id' => $candidate->id, 'actor_id' => $this->operator->id];
        $results = $this->parallel($base + ['correlation_id' => 'accept-a'], $base + ['correlation_id' => 'accept-b']);
        $this->assertSame(1, collect($results)->where('outcome', 'ACCEPTED')->count());
        $this->assertSame(1, collect($results)->where('outcome', 'RULE')->count());
        $this->assertDatabaseCount('reconciliation_matches', 1);
        $this->assertSame('ACCEPTED', $candidate->fresh()->status->value);
    }

    public function test_matching_generation_is_deterministic_and_deduplicated_on_mariadb(): void
    {
        $session = $this->createReconciliationSession('2026-08-01', '2026-08-31');
        $this->title('75.00', 'DET-42', '2026-08-15');
        $this->transaction('75.00', 'DET-42', '2026-08-15');
        $engine = app(ReconciliationMatchingEngine::class);
        $engine->generate($session->id, $this->operator->id, 'det-a');
        $first = ReconciliationCandidate::query()->firstOrFail();
        $snapshot = [$first->score, $first->evidence, $first->signature_hash];
        $engine->generate($session->id, $this->operator->id, 'det-b');
        $second = ReconciliationCandidate::query()->firstOrFail();
        $this->assertSame($snapshot, [$second->score, $second->evidence, $second->signature_hash]);
        $this->assertDatabaseCount('reconciliation_candidates', 1);
    }

    // ---------------------------------------------------------------------
    // Fase 6 — fechamento e reabertura sob concorrência real.
    //
    // `HOMOLOGACAO_MARIADB_FINAL.md` exige estes cenários em processos
    // independentes. A suíte SQLite prova as regras sequencialmente, mas não
    // prova lock InnoDB: `close()` só é seguro se o `lockForUpdate()` da sessão
    // realmente serializar dois fechamentos simultâneos.
    // ---------------------------------------------------------------------

    public function test_parallel_close_of_the_same_session_creates_exactly_one_closure(): void
    {
        $session = $this->createReconciliationSession('2026-08-01', '2026-08-31');
        $this->confirmMatch($session->id, '400.00', 'CLOSE-RACE');

        $base = ['mode' => 'close', 'session_id' => $session->id, 'actor_id' => $this->operator->id];
        $results = $this->parallel($base + ['correlation_id' => 'close-a'], $base + ['correlation_id' => 'close-b']);

        $this->assertSame(1, collect($results)->where('outcome', 'CLOSED')->count(), $this->describe($results));
        $this->assertSame(1, collect($results)->where('outcome', 'RULE')->count(), $this->describe($results));
        $this->assertSame('CLOSURE_SESSION_ALREADY_CLOSED', collect($results)->firstWhere('outcome', 'RULE')['rule']);
        $this->assertDatabaseCount('reconciliation_closures', 1);
        $this->assertSame('CLOSED', $session->fresh()->status->value);
    }

    public function test_parallel_reopen_of_the_same_closure_creates_exactly_one_reopening(): void
    {
        config(['reconciliation.closing_enabled' => true]);
        $session = $this->createReconciliationSession('2026-08-01', '2026-08-31');
        $this->confirmMatch($session->id, '400.00', 'REOPEN-RACE');
        $closure = app(ReconciliationClosureService::class)->close($session->id, $this->operator->id, 'close-before-reopen');

        $base = [
            'mode' => 'reopen', 'session_id' => $session->id, 'closure_id' => $closure->id,
            'reason' => 'Reabertura sintética de homologação', 'actor_id' => $this->operator->id,
        ];
        $results = $this->parallel($base + ['correlation_id' => 'reopen-a'], $base + ['correlation_id' => 'reopen-b']);

        $this->assertSame(1, collect($results)->where('outcome', 'REOPENED')->count(), $this->describe($results));
        $this->assertSame(1, collect($results)->where('outcome', 'RULE')->count(), $this->describe($results));
        $this->assertSame('CLOSURE_NOT_CLOSED', collect($results)->firstWhere('outcome', 'RULE')['rule']);
        // A história anterior não pode ser reescrita: um único registro de reabertura
        // e o fechamento original preservado, apenas com status atualizado.
        $this->assertDatabaseCount('reconciliation_reopenings', 1);
        $this->assertDatabaseCount('reconciliation_closures', 1);
        $this->assertSame('REOPENED', $closure->fresh()->status->value);
    }

    public function test_close_concurrent_with_manual_confirmation_never_leaves_a_confirmed_match_outside_the_closure(): void
    {
        $session = $this->createReconciliationSession('2026-08-01', '2026-08-31');
        $title = $this->title('500.00', 'RACE-TITLE', '2026-08-15');
        $transaction = $this->transaction('500.00', 'RACE-TX', '2026-08-15');

        $results = $this->parallel(
            ['mode' => 'close', 'session_id' => $session->id, 'actor_id' => $this->operator->id, 'correlation_id' => 'race-close'],
            [
                'mode' => 'manual_confirm', 'session_id' => $session->id, 'title_id' => $title->id,
                'installment_id' => $title->installments->first()->id, 'transaction_id' => $transaction->id,
                'amount' => '500.00', 'actor_id' => $this->operator->id, 'correlation_id' => 'race-confirm',
            ],
        );

        // Qualquer das duas ordens é aceitável; o que não pode acontecer é um match
        // CONFIRMED existir na sessão sem estar capturado no snapshot do fechamento.
        $this->assertSame(1, collect($results)->where('outcome', 'CLOSED')->count(), $this->describe($results));
        $this->assertDatabaseCount('reconciliation_closures', 1);

        $confirmed = ReconciliationMatch::query()
            ->where('reconciliation_session_id', $session->id)
            ->where('status', ReconciliationMatchStatus::Confirmed->value)
            ->count();
        $captured = DB::table('reconciliation_closure_matches')->count();

        $this->assertSame(
            $confirmed,
            $captured,
            'Match confirmado ficou fora do fechamento. '.$this->describe($results),
        );

        $manual = collect($results)->first(fn (array $row): bool => in_array($row['outcome'], ['MATCH', 'RULE'], true));
        if ($manual !== null && $manual['outcome'] === 'RULE') {
            // Perdeu a corrida: precisa ter sido recusado pela regra de sessão
            // fechada, não por outro motivo qualquer.
            $this->assertSame('RECONCILIATION_SESSION_CLOSED', $manual['rule'], $this->describe($results));
            $this->assertSame(0, $confirmed);
        }
    }

    public function test_parallel_close_of_overlapping_periods_allows_only_one_closure(): void
    {
        // Mesma conta, períodos sobrepostos, fechados ao mesmo tempo. Dois
        // fechamentos vigentes sobrepostos seriam uma violação de integridade
        // financeira: o mesmo fato bancário entraria em dois fechamentos.
        $first = $this->createReconciliationSession('2026-08-01', '2026-08-31');
        $second = $this->createReconciliationSession('2026-08-15', '2026-09-15');

        $results = $this->parallel(
            ['mode' => 'close', 'session_id' => $first->id, 'actor_id' => $this->operator->id, 'correlation_id' => 'overlap-a'],
            ['mode' => 'close', 'session_id' => $second->id, 'actor_id' => $this->operator->id, 'correlation_id' => 'overlap-b'],
        );

        $this->assertSame(1, collect($results)->where('outcome', 'CLOSED')->count(), $this->describe($results));
        $this->assertSame(1, collect($results)->where('outcome', 'RULE')->count(), $this->describe($results));
        $this->assertSame('CLOSURE_PERIOD_OVERLAP', collect($results)->firstWhere('outcome', 'RULE')['rule'], $this->describe($results));
        $this->assertDatabaseCount('reconciliation_closures', 1);
    }

    /**
     * Cria e confirma um match 1:1 no processo do teste, para que a sessão tenha
     * conteúdo real a ser capturado no fechamento.
     */
    private function confirmMatch(int $sessionId, string $amount, string $document): void
    {
        $title = $this->title($amount, $document.'-T', '2026-08-15');
        $transaction = $this->transaction($amount, $document.'-X', '2026-08-15');

        app(ManualReconciliationService::class)->confirm(
            $sessionId,
            [new ReconciliationTitleAllocationData($title->id, $title->installments->first()->id, $amount)],
            [new ReconciliationTransactionAllocationData($transaction->id, $amount)],
            $this->operator->id,
            'seed-'.$document,
        );
    }

    /** @param array<int, array<string, mixed>> $results */
    private function describe(array $results): string
    {
        return 'Resultados dos workers: '.json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return list<array<string, mixed>> */
    private function parallel(array $left, array $right): array
    {
        $startAt = microtime(true) + 0.8;
        $files = [];
        $processes = [];
        foreach ([$left, $right] as $index => $payload) {
            $file = tempnam(sys_get_temp_dir(), 'acop-hml-');
            file_put_contents($file, json_encode($payload + ['start_at' => $startAt], JSON_THROW_ON_ERROR));
            $files[] = $file;
            $processes[$index] = new Process([PHP_BINARY, base_path('tools/homologation/concurrency-worker.php'), $file], base_path(), null, null, 45);
            $processes[$index]->start();
        }
        $results = [];
        try {
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            }
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        $this->assertLessThan(0.15, abs($results[0]['started_at'] - $results[1]['started_at']), 'Workers não iniciaram concorrentemente.');
        $this->assertFalse(collect($results)->contains(fn (array $result): bool => $result['outcome'] === 'ERROR'), json_encode($results));

        return $results;
    }

    private function statuses(array $results): array
    {
        $statuses = collect($results)->pluck('status')->sort()->values()->all();

        return $statuses;
    }

    /**
     * Compara os status dos workers anexando os corpos das respostas à mensagem.
     *
     * Sem isto, uma divergência em processo paralelo aparece apenas como
     * "esperado [200,201], recebido [422,422]", sem o motivo — e o worker roda
     * em outro processo, então não há exceção para inspecionar.
     *
     * @param  list<int>  $expected
     * @param  array<int, array<string, mixed>>  $results
     */
    private function assertStatuses(array $expected, array $results): void
    {
        $this->assertSame($expected, $this->statuses($results), $this->describe($results));
    }

    private function titlePayload(string $externalId): array
    {
        return ['external_id' => $externalId, 'issue_date' => '2026-08-01', 'due_date' => '2026-08-15', 'original_amount' => '100.00', 'discount_amount' => '0.00', 'addition_amount' => '0.00', 'currency' => 'BRL', 'installment_count' => 1];
    }

    private function bankPayload(string $externalId): array
    {
        return ['account_id' => 1, 'external_id' => $externalId, 'transaction_date' => '2026-08-15', 'direction' => 'DEBIT', 'amount' => '100.00', 'currency' => 'BRL', 'description' => 'Fato sintético igual'];
    }

    private function createReconciliationSession(string $start, string $end)
    {
        return app(ReconciliationSessionService::class)->create(1, $start, $end, $this->operator->id, (string) Str::uuid());
    }

    private function title(string $amount, string $document, string $date)
    {
        return app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: 'HML-'.$document, type: FinancialTitleType::Payable,
            issueDate: $date, dueDate: $date, originalAmount: $amount, partyName: 'Parte sintética', documentNumber: $document, accountId: 1,
        ), $this->operator->id)->title->load('installments');
    }

    private function transaction(string $amount, string $document, string $date): BankTransaction
    {
        $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();
        $batch = ImportBatch::query()->create(['source_system_id' => $source->id, 'account_id' => 1, 'channel' => 'API', 'format' => 'CANONICAL_API', 'status' => 'COMPLETED', 'total_items' => 1, 'imported_items' => 1, 'correlation_id' => (string) Str::uuid(), 'started_at' => now(), 'completed_at' => now()]);

        return BankTransaction::query()->create([
            'account_id' => 1, 'source_system_id' => $source->id, 'import_batch_id' => $batch->id,
            'external_id' => 'HML-'.$document, 'identity_quality' => 'STRONG', 'direction' => 'DEBIT',
            'amount' => $amount, 'currency' => 'BRL', 'transaction_date' => $date,
            'description_original' => 'Parte sintética '.$document, 'document_number' => $document,
            'payload_hash' => hash('sha256', $document), 'raw_hash' => hash('sha256', 'raw-'.$document),
        ]);
    }
}
