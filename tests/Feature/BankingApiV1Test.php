<?php

namespace Tests\Feature;

use App\Application\Financial\TitleIngestionService;
use App\Application\Integration\IntegrationCredentialService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\IntegrationClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

class BankingApiV1Test extends TestCase
{
    use RefreshesTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('contas', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('nome');
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('contas')->insert([
            'id' => 1,
            'nome' => 'Conta de teste',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_bank_transaction_authentication_scope_and_account_validation(): void
    {
        $this->postJson('/api/v1/bank-transactions', $this->payload('AUTH-1'))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        [, $readToken] = $this->issue(['bank-transactions:read']);
        $this->withToken($readToken)
            ->withHeaders(['Idempotency-Key' => 'wrong-scope'])
            ->postJson('/api/v1/bank-transactions', $this->payload('AUTH-2'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        [, $writeToken] = $this->issue(['bank-transactions:write']);
        $missingAccount = $this->payload('AUTH-3');
        $missingAccount['account_id'] = 999;
        $this->postBankTransaction($writeToken, 'missing-account', $missingAccount)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'BANK_ACCOUNT_NOT_FOUND');

        $missingIdentity = $this->payload('AUTH-4');
        unset($missingIdentity['external_id']);
        $this->postBankTransaction($writeToken, 'missing-identity', $missingIdentity)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['external_id']]]);
    }

    public function test_canonical_api_creates_credit_and_debit_and_supports_safe_lookup(): void
    {
        [, $token] = $this->issue(['bank-transactions:read', 'bank-transactions:write']);
        $credit = $this->postBankTransaction($token, 'credit-create', $this->payload('TX-CREDIT'))
            ->assertCreated()
            ->assertJsonPath('data.direction', 'CREDIT')
            ->assertJsonPath('data.amount', '1250.00')
            ->assertJsonPath('data.description', 'PIX RECEBIDO CLIENTE X')
            ->assertJsonPath('meta.decision', 'CREATED');

        $debitPayload = $this->payload('TX-DEBIT');
        $debitPayload['direction'] = 'DEBIT';
        $debitPayload['amount'] = '35.00';
        $this->postBankTransaction($token, 'debit-create', $debitPayload)
            ->assertCreated()
            ->assertJsonPath('data.direction', 'DEBIT')
            ->assertJsonPath('data.amount', '35.00');

        $this->withToken($token)->getJson('/api/v1/bank-transactions/1/TX-CREDIT')
            ->assertOk()
            ->assertJsonPath('data.id', $credit->json('data.id'))
            ->assertJsonMissingPath('data.payload_hash')
            ->assertJsonMissingPath('data.raw_hash');
        $this->assertDatabaseCount('bank_transactions', 2);
    }

    public function test_strong_identity_deduplicates_across_http_keys_but_never_by_value_and_date(): void
    {
        [, $token] = $this->issue(['bank-transactions:write']);
        $payload = $this->payload('STRONG-ID');

        $first = $this->postBankTransaction($token, 'strong-first', $payload)->assertCreated();
        $duplicate = $this->postBankTransaction($token, 'strong-second', $payload)
            ->assertOk()
            ->assertJsonPath('meta.decision', 'DUPLICATE');
        $this->assertSame($first->json('data.id'), $duplicate->json('data.id'));
        $this->assertDatabaseCount('bank_transactions', 1);
        $this->assertDatabaseCount('import_batches', 2);
        $this->assertDatabaseHas('import_batch_items', ['result' => 'DUPLICATE']);

        $sameFactA = $this->payload('LEGIT-A');
        $sameFactB = $this->payload('LEGIT-B');
        $this->postBankTransaction($token, 'legit-a', $sameFactA)->assertCreated();
        $this->postBankTransaction($token, 'legit-b', $sameFactB)->assertCreated();
        $this->assertDatabaseCount('bank_transactions', 3);
    }

    public function test_same_strong_identity_with_changed_content_is_a_conflict(): void
    {
        [, $token] = $this->issue(['bank-transactions:write']);
        $payload = $this->payload('IMMUTABLE-ID');
        $this->postBankTransaction($token, 'immutable-first', $payload)->assertCreated();

        $payload['amount'] = '999.00';
        $this->postBankTransaction($token, 'immutable-changed', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'BANK_TRANSACTION_ID_CONFLICT');
        $this->assertDatabaseCount('bank_transactions', 1);
    }

    public function test_transaction_identity_and_lookup_are_isolated_by_source(): void
    {
        [, $tokenA] = $this->issue(['bank-transactions:read', 'bank-transactions:write'], 'BANK_IMPORT');
        [, $tokenB] = $this->issue(['bank-transactions:read', 'bank-transactions:write'], 'API');
        $payload = $this->payload('SOURCE-SHARED');

        $a = $this->postBankTransaction($tokenA, 'source-a', $payload)->assertCreated();
        $b = $this->postBankTransaction($tokenB, 'source-b', $payload)->assertCreated();
        $this->assertNotSame($a->json('data.id'), $b->json('data.id'));
        $this->assertDatabaseCount('bank_transactions', 2);

        $this->withToken($tokenA)->getJson('/api/v1/bank-transactions/1/SOURCE-SHARED')
            ->assertJsonPath('data.id', $a->json('data.id'));
        $this->withToken($tokenB)->getJson('/api/v1/bank-transactions/1/SOURCE-SHARED')
            ->assertJsonPath('data.id', $b->json('data.id'));
    }

    public function test_canonical_bank_api_reuses_http_idempotency_contract(): void
    {
        [, $token] = $this->issue(['bank-transactions:write']);
        $payload = $this->payload('HTTP-IDEMP');

        $first = $this->postBankTransaction($token, 'http-bank-key', $payload)->assertCreated();
        $replay = $this->postBankTransaction($token, 'http-bank-key', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('meta.idempotency_replayed', true);
        $this->assertSame($first->json('data'), $replay->json('data'));

        $payload['amount'] = '1300.00';
        $this->postBankTransaction($token, 'http-bank-key', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertDatabaseCount('bank_transactions', 1);
    }

    public function test_valid_ofx_creates_batch_transactions_items_audit_and_queries(): void
    {
        [$client, $token] = $this->issue(['bank-imports:read', 'bank-imports:write']);

        $response = $this->postOfx($token, 'ofx-valid', 'statement-valid.ofx')
            ->assertCreated()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.total_items', 3)
            ->assertJsonPath('data.imported_items', 3)
            ->assertJsonPath('data.duplicate_items', 0)
            ->assertJsonPath('data.rejected_items', 0)
            ->assertJsonPath('meta.decision', 'IMPORTED')
            ->assertJsonMissingPath('data.metadata');
        $batchId = $response->json('data.id');

        $this->assertDatabaseHas('bank_transactions', [
            'external_id' => 'FIT-CREDIT-001',
            'direction' => 'CREDIT',
            'amount' => '5000.00',
        ]);
        $this->assertDatabaseHas('bank_transactions', [
            'external_id' => 'FIT-DEBIT-001',
            'direction' => 'DEBIT',
            'amount' => '1200.00',
        ]);
        $this->assertDatabaseHas('import_batches', [
            'id' => $batchId,
            'source_system_id' => $client->source_system_id,
            'integration_client_id' => $client->id,
            'period_start' => '2026-08-01 00:00:00',
            'period_end' => '2026-08-31 00:00:00',
        ]);
        $this->assertDatabaseCount('import_batch_items', 3);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'BANK_IMPORT_COMPLETED',
            'entity_id' => (string) $batchId,
        ]);

        $this->withToken($token)->getJson("/api/v1/bank-imports/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.total_items', 3);
        $this->withToken($token)->getJson("/api/v1/bank-imports/{$batchId}/items")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.pagination.total', 3);
    }

    public function test_ofx_http_replay_hashes_file_content_and_rejects_changed_file(): void
    {
        [, $token] = $this->issue(['bank-imports:write']);
        $first = $this->postOfx($token, 'ofx-replay-key', 'statement-valid.ofx')->assertCreated();
        $replay = $this->postOfx($token, 'ofx-replay-key', 'statement-valid.ofx')
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('meta.idempotency_replayed', true);
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));

        $this->postOfx($token, 'ofx-replay-key', 'statement-overlap-a.ofx')
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertDatabaseCount('import_batches', 1);
    }

    public function test_same_file_with_another_http_key_returns_existing_batch_without_duplicates(): void
    {
        [, $token] = $this->issue(['bank-imports:write']);
        $first = $this->postOfx($token, 'file-first-key', 'statement-valid.ofx')->assertCreated();
        $duplicate = $this->postOfx($token, 'file-second-key', 'statement-valid.ofx')
            ->assertOk()
            ->assertJsonPath('meta.decision', 'FILE_DUPLICATE');

        $this->assertSame($first->json('data.id'), $duplicate->json('data.id'));
        $this->assertDatabaseCount('import_batches', 1);
        $this->assertDatabaseCount('bank_transactions', 3);
    }

    public function test_overlapping_ofx_files_deduplicate_fitid_but_keep_unique_and_legitimate_equal_lines(): void
    {
        [, $token] = $this->issue(['bank-imports:write']);
        $this->postOfx($token, 'overlap-a', 'statement-overlap-a.ofx')
            ->assertCreated()
            ->assertJsonPath('data.imported_items', 2);
        $second = $this->postOfx($token, 'overlap-b', 'statement-overlap-b.ofx')
            ->assertCreated()
            ->assertJsonPath('data.imported_items', 1)
            ->assertJsonPath('data.duplicate_items', 1)
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseCount('bank_transactions', 3);
        $this->assertDatabaseHas('import_batch_items', [
            'import_batch_id' => $second->json('data.id'),
            'external_id' => 'OVERLAP-COMMON',
            'result' => 'DUPLICATE',
        ]);
        $this->assertSame(3, BankTransaction::query()->where('amount', '100.00')->count());
    }

    public function test_structurally_valid_file_with_one_bad_item_finishes_partial(): void
    {
        [, $token] = $this->issue(['bank-imports:write']);
        $response = $this->postOfx($token, 'partial-file', 'statement-partial.ofx')
            ->assertCreated()
            ->assertJsonPath('data.status', 'PARTIAL')
            ->assertJsonPath('data.total_items', 4)
            ->assertJsonPath('data.imported_items', 3)
            ->assertJsonPath('data.rejected_items', 1);

        $this->assertDatabaseHas('import_batch_items', [
            'import_batch_id' => $response->json('data.id'),
            'result' => 'REJECTED',
            'error_code' => 'BANK_TRANSACTION_ID_REQUIRED',
        ]);
        $this->assertDatabaseCount('bank_transactions', 3);
    }

    public function test_ofx_upload_rejects_empty_invalid_oversized_false_extension_and_xxe_safely(): void
    {
        [, $token] = $this->issue(['bank-imports:write']);

        $this->postRawFile($token, 'empty-ofx', 'empty.ofx', '')
            ->assertUnprocessable();
        $this->postRawFile($token, 'invalid-ofx', 'invalid.ofx', 'not an ofx')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'BANK_IMPORT_INVALID_FILE');
        $this->postRawFile($token, 'false-extension', 'statement.txt', $this->fixture('statement-valid.ofx'))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'BANK_IMPORT_UNSUPPORTED_FORMAT');
        $xxe = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY leak SYSTEM "file:///etc/passwd">]><OFX><BANKTRANLIST></BANKTRANLIST></OFX>';
        $this->postRawFile($token, 'xxe-ofx', 'xxe.ofx', $xxe)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'BANK_IMPORT_INVALID_FILE');

        config(['banking.ofx_max_bytes' => 10]);
        $this->postRawFile($token, 'large-ofx', 'large.ofx', str_repeat('X', 100))
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'BANK_IMPORT_TOO_LARGE');

        $this->assertSame(5, DB::table('import_batches')->where('status', 'FAILED')->count());
        $this->assertDatabaseCount('bank_transactions', 0);
    }

    public function test_batch_and_transaction_queries_cannot_cross_source_boundaries(): void
    {
        [, $tokenA] = $this->issue(['bank-imports:read', 'bank-imports:write'], 'BANK_IMPORT');
        [, $tokenB] = $this->issue(['bank-imports:read', 'bank-transactions:read'], 'API');
        $batch = $this->postOfx($tokenA, 'isolation-file', 'statement-valid.ofx')->assertCreated();

        $this->withToken($tokenB)->getJson('/api/v1/bank-imports/'.$batch->json('data.id'))
            ->assertNotFound()
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
        $this->withToken($tokenB)->getJson('/api/v1/bank-transactions/1/FIT-CREDIT-001')
            ->assertNotFound();
    }

    public function test_bank_import_never_changes_titles_settlements_or_legacy_movements(): void
    {
        $title = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'AGROCOLITTI',
            externalId: 'TITLE-UNCHANGED',
            type: FinancialTitleType::Payable,
            issueDate: '2026-08-13',
            dueDate: '2026-08-20',
            originalAmount: '1250.00',
            accountId: 1,
        ))->title;
        [, $token] = $this->issue(['bank-imports:write']);

        $this->postOfx($token, 'no-matching', 'statement-valid.ofx')->assertCreated();

        $this->assertSame('OPEN', FinancialTitle::query()->findOrFail($title->id)->status->value);
        $this->assertDatabaseCount('title_settlements', 0);
        $this->assertFalse(Schema::hasTable('movimentos'));
        $this->assertDatabaseCount('bank_transactions', 3);
    }

    /**
     * @param  list<string>  $scopes
     * @return array{IntegrationClient, string}
     */
    private function issue(array $scopes, string $source = 'BANK_IMPORT'): array
    {
        $issued = app(IntegrationCredentialService::class)->issue($source, 'Banco teste '.$source, $scopes);

        return [$issued->client->load('sourceSystem'), $issued->plainTextToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $externalId): array
    {
        return [
            'account_id' => 1,
            'external_id' => $externalId,
            'transaction_date' => '2026-08-13',
            'posted_at' => '2026-08-13T10:30:00-03:00',
            'direction' => 'CREDIT',
            'amount' => '1250.00',
            'currency' => 'BRL',
            'description' => 'PIX RECEBIDO CLIENTE X',
            'bank_reference' => 'ABC123',
            'counterparty' => ['name' => 'Cliente X'],
        ];
    }

    private function postBankTransaction(
        string $token,
        string $key,
        array $payload,
    ): TestResponse {
        return $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/bank-transactions', $payload);
    }

    private function postOfx(string $token, string $key, string $fixture): TestResponse
    {
        return $this->postRawFile($token, $key, $fixture, $this->fixture($fixture));
    }

    private function postRawFile(string $token, string $key, string $filename, string $contents): TestResponse
    {
        return $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => $key, 'Accept' => 'application/json'])
            ->post('/api/v1/bank-imports/ofx', [
                'account_id' => 1,
                'file' => UploadedFile::fake()->createWithContent($filename, $contents),
            ]);
    }

    private function fixture(string $filename): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/Banking/'.$filename));
    }
}
