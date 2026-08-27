<?php

namespace Tests\Feature;

use App\Application\Financial\InstallmentScheduleService;
use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Application\Integration\IntegrationCredentialService;
use App\Contracts\AuditEventRecorder;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Models\FinancialTitle;
use App\Models\IntegrationClient;
use Illuminate\Testing\TestResponse;
use Mockery;
use RuntimeException;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

class IntegrationApiV1Test extends TestCase
{
    use RefreshesTestDatabase;

    public function test_authentication_inactive_source_and_scope_are_enforced(): void
    {
        $this->getJson('/api/v1/receivables/REC-1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertHeader('X-Correlation-ID');

        $this->withToken('invalid-token')->getJson('/api/v1/receivables/REC-1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        [$client, $token] = $this->issue(['payables:read']);
        $this->withToken($token)->getJson('/api/v1/receivables/REC-1')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $client->update(['active' => false]);
        $this->withToken($token)->getJson('/api/v1/payables/PAY-1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        [$sourceClient, $sourceToken] = $this->issue(['receivables:read'], 'NFSE');
        $sourceClient->sourceSystem->update(['active' => false]);
        $this->withToken($sourceToken)->getJson('/api/v1/receivables/REC-1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'SOURCE_SYSTEM_INACTIVE');
    }

    public function test_receivable_post_get_and_put_use_route_type_source_and_correlation(): void
    {
        [$client, $token] = $this->issue(['receivables:read', 'receivables:write']);
        $payload = $this->payload('REC-100');

        $created = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'rec-create-100', 'X-Correlation-ID' => 'corr-rec-100'])
            ->postJson('/api/v1/receivables', $payload)
            ->assertCreated()
            ->assertHeader('X-Correlation-ID', 'corr-rec-100')
            ->assertHeader('Idempotency-Replayed', 'false')
            ->assertJsonPath('data.external_id', 'REC-100')
            ->assertJsonPath('data.type', 'RECEIVABLE')
            ->assertJsonPath('data.total_amount', '100.00')
            ->assertJsonPath('data.installment_count', 2)
            ->assertJsonPath('meta.decision', 'CREATED');

        $titleId = $created->json('data.id');
        $this->assertDatabaseHas('audit_events', [
            'entity_id' => (string) $titleId,
            'integration_client_id' => $client->id,
            'correlation_id' => 'corr-rec-100',
        ]);

        $this->withToken($token)->getJson('/api/v1/receivables/REC-100')
            ->assertOk()
            ->assertJsonPath('data.id', $titleId)
            ->assertJsonMissingPath('data.payload_hash')
            ->assertJsonMissingPath('data.idempotency_key');

        $updatedPayload = $payload;
        unset($updatedPayload['external_id']);
        $updatedPayload['original_amount'] = '120.00';
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'rec-update-100'])
            ->putJson('/api/v1/receivables/REC-100', $updatedPayload)
            ->assertOk()
            ->assertJsonPath('data.total_amount', '120.00')
            ->assertJsonPath('meta.decision', 'UPDATED');

        $this->assertSame(FinancialTitleType::Receivable, FinancialTitle::query()->findOrFail($titleId)->type);
    }

    public function test_payload_cannot_override_type_source_or_internal_fields(): void
    {
        [, $token] = $this->issue(['receivables:write']);
        $payload = $this->payload('REC-BLOCKED') + [
            'type' => 'PAYABLE',
            'source_system_id' => 999,
            'status' => 'SETTLED',
        ];

        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'blocked-fields'])
            ->postJson('/api/v1/receivables', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['type', 'source_system_id', 'status']]]);

        $this->assertDatabaseCount('financial_titles', 0);
    }

    public function test_validation_and_missing_idempotency_use_standard_errors(): void
    {
        [, $token] = $this->issue(['payables:write']);

        $this->withToken($token)->postJson('/api/v1/payables', $this->payload('PAY-1'))
            ->assertBadRequest()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $invalid = $this->payload('PAY-2');
        $invalid['issue_date'] = '13/08/2026';
        $invalid['due_date'] = '2026-08-01';
        $invalid['original_amount'] = 100.0;

        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'pay-invalid'])
            ->postJson('/api/v1/payables', $invalid)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['issue_date', 'due_date', 'original_amount']]]);

        $this->assertDatabaseHas('integration_requests', [
            'status' => 'COMPLETED',
            'response_status' => 422,
        ]);
    }

    public function test_http_idempotency_replays_and_rejects_different_request_or_route(): void
    {
        [, $token] = $this->issue(['payables:write', 'receivables:write']);
        $payload = $this->payload('PAY-IDEMP');
        $headers = ['Idempotency-Key' => 'same-key'];

        $first = $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/payables', $payload)
            ->assertCreated();
        $replay = $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/payables', $payload)
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('meta.idempotency_replayed', true);

        $this->assertSame($first->json('data'), $replay->json('data'));
        $this->assertSame($first->getStatusCode(), $replay->getStatusCode());
        $this->assertDatabaseCount('financial_titles', 1);
        $this->assertDatabaseCount('integration_requests', 1);

        $changed = $payload;
        $changed['original_amount'] = '900.00';
        $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/payables', $changed)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/receivables', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_same_external_id_is_scoped_by_source_and_different_keys_do_not_duplicate(): void
    {
        [$clientA, $tokenA] = $this->issue(['receivables:read', 'receivables:write'], 'AGROCOLITTI');
        [$clientB, $tokenB] = $this->issue(['receivables:read', 'receivables:write'], 'NFSE');
        $payload = $this->payload('REC-SHARED');

        $first = $this->createViaApi($tokenA, 'shared-a-1', '/api/v1/receivables', $payload)->assertCreated();
        $sameResource = $this->createViaApi($tokenA, 'shared-a-2', '/api/v1/receivables', $payload)
            ->assertOk()
            ->assertJsonPath('meta.decision', 'IGNORED');
        $otherSource = $this->createViaApi($tokenB, 'shared-b-1', '/api/v1/receivables', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $sameResource->json('data.id'));
        $this->assertNotSame($first->json('data.id'), $otherSource->json('data.id'));
        $this->assertDatabaseCount('financial_titles', 2);

        $this->withToken($tokenA)->getJson('/api/v1/receivables/REC-SHARED')
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertNotSame($clientA->source_system_id, $clientB->source_system_id);
    }

    public function test_payable_matrix_and_business_cancellation_are_enforced(): void
    {
        [$client, $token] = $this->issue(['payables:read', 'payables:write']);
        $created = $this->createViaApi($token, 'pay-create', '/api/v1/payables', $this->payload('PAY-CANCEL'))
            ->assertCreated()
            ->assertJsonPath('data.type', 'PAYABLE');

        $cancelPayload = ['reason' => 'Documento cancelado no sistema de origem'];
        $cancelled = $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'pay-cancel'])
            ->postJson('/api/v1/payables/PAY-CANCEL/cancel', $cancelPayload)
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('meta.decision', 'CANCELLED');
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'pay-cancel'])
            ->postJson('/api/v1/payables/PAY-CANCEL/cancel', $cancelPayload)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertDatabaseHas('financial_titles', [
            'id' => $created->json('data.id'),
            'status' => 'CANCELLED',
            'deleted_at' => null,
        ]);
        $this->assertDatabaseHas('title_cancellations', [
            'financial_title_id' => $created->json('data.id'),
            'integration_client_id' => $client->id,
            'reason' => $cancelPayload['reason'],
        ]);
        $this->assertDatabaseCount('title_cancellations', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'FINANCIAL_TITLE_CANCELLED']);
        $cancelled->assertJsonPath('data.cancellation.reason', $cancelPayload['reason']);
    }

    public function test_title_with_settlement_cannot_be_cancelled_or_updated(): void
    {
        [, $token] = $this->issue(['receivables:write']);
        $payload = $this->payload('REC-SETTLED');
        $created = $this->createViaApi($token, 'settled-create', '/api/v1/receivables', $payload)->assertCreated();
        $title = FinancialTitle::query()->findOrFail($created->json('data.id'));
        app(SettlementService::class)->settle($title->id, '10.00', '2026-08-20', $title->installments()->first()->id);

        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'settled-cancel'])
            ->postJson('/api/v1/receivables/REC-SETTLED/cancel', ['reason' => 'Cancelamento tardio'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'TITLE_ALREADY_SETTLED');

        unset($payload['external_id']);
        $payload['original_amount'] = '110.00';
        $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => 'settled-update'])
            ->putJson('/api/v1/receivables/REC-SETTLED', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'TITLE_UPDATE_NOT_ALLOWED');
    }

    public function test_rate_limit_is_applied_per_integration_client(): void
    {
        config(['integrations.rate_limit_per_minute' => 1]);
        [, $token] = $this->issue(['receivables:read']);

        $this->withToken($token)->getJson('/api/v1/receivables/none')->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/receivables/none')
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'RATE_LIMIT_EXCEEDED');
    }

    public function test_transient_failure_is_not_replayed_forever_and_same_key_can_retry(): void
    {
        [, $token] = $this->issue(['receivables:write']);
        $realService = new TitleIngestionService(
            app(InstallmentScheduleService::class),
            app(AuditEventRecorder::class),
        );
        $calls = 0;
        $service = Mockery::mock(TitleIngestionService::class)->makePartial();
        $service->shouldReceive('ingest')->twice()->andReturnUsing(
            function (...$arguments) use (&$calls, $realService) {
                $calls++;
                if ($calls === 1) {
                    throw new RuntimeException('Falha transitória simulada.');
                }

                return $realService->ingest(...$arguments);
            },
        );
        $this->app->instance(TitleIngestionService::class, $service);
        $headers = ['Idempotency-Key' => 'retry-after-500'];
        $payload = $this->payload('REC-RETRY');

        $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/receivables', $payload)
            ->assertInternalServerError()
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonMissingPath('exception');
        $this->assertDatabaseCount('financial_titles', 0);
        $this->assertDatabaseHas('integration_requests', [
            'status' => 'FAILED',
            'failure_code' => 'TRANSIENT_HTTP_ERROR',
            'response_body' => null,
        ]);

        $this->withToken($token)->withHeaders($headers)
            ->postJson('/api/v1/receivables', $payload)
            ->assertCreated()
            ->assertJsonPath('data.external_id', 'REC-RETRY');
        $this->assertDatabaseCount('financial_titles', 1);
        $this->assertDatabaseHas('integration_requests', [
            'status' => 'COMPLETED',
            'response_status' => 201,
            'failure_code' => null,
        ]);
    }

    public function test_credential_service_stores_only_hash_and_revocation_takes_effect(): void
    {
        $issued = app(IntegrationCredentialService::class)->issue(
            'AGROCOLITTI',
            'Credencial segura',
            ['payables:read'],
        );

        $this->assertStringStartsWith('acop_', $issued->plainTextToken);
        $this->assertSame(hash('sha256', $issued->plainTextToken), $issued->client->token_hash);
        $this->assertNotSame($issued->plainTextToken, $issued->client->token_hash);
        $this->assertDatabaseMissing('integration_clients', ['token_hash' => $issued->plainTextToken]);
        $this->assertTrue(app(IntegrationCredentialService::class)->revoke($issued->client->id));

        $this->withToken($issued->plainTextToken)->getJson('/api/v1/payables/none')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    /**
     * @param  list<string>  $scopes
     * @return array{IntegrationClient, string}
     */
    private function issue(array $scopes, string $sourceCode = 'AGROCOLITTI'): array
    {
        $issued = app(IntegrationCredentialService::class)->issue($sourceCode, 'Teste '.$sourceCode, $scopes);

        return [$issued->client->load('sourceSystem'), $issued->plainTextToken];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $externalId): array
    {
        return [
            'external_id' => $externalId,
            'document_number' => 'DOC-100',
            'issue_date' => '2026-08-13',
            'due_date' => '2026-08-20',
            'original_amount' => '100.00',
            'discount_amount' => '0.00',
            'addition_amount' => '0.00',
            'currency' => 'BRL',
            'party' => ['type' => 'CUSTOMER', 'name' => 'Cliente Teste'],
            'installment_count' => 2,
            'notes' => 'Contrato HTTP v1',
        ];
    }

    private function createViaApi(
        string $token,
        string $idempotencyKey,
        string $uri,
        array $payload,
    ): TestResponse {
        return $this->withToken($token)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->postJson($uri, $payload);
    }
}
