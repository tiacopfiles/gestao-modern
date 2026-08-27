<?php

namespace Tests\Feature;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Contracts\AuditEventRecorder;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\IngestionDecision;
use App\Domain\Financial\Enums\SettlementType;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\TitleIngestionData;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use DomainException;
use RuntimeException;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

class FinancialCoreTest extends TestCase
{
    use RefreshesTestDatabase;

    public function test_it_creates_payable_with_decimal_values_and_initial_status(): void
    {
        $result = $this->ingestion()->ingest($this->titleData(
            type: FinancialTitleType::Payable,
            externalId: 'PAY-1',
            originalAmount: '1000.00',
            discountAmount: '10.25',
            additionAmount: '5.30',
        ));

        $this->assertSame(IngestionDecision::Created, $result->decision);
        $this->assertSame(FinancialTitleType::Payable, $result->title->type);
        $this->assertSame(TitleStatus::Open, $result->title->status);
        $this->assertSame('995.05', $result->title->total_amount);
        $this->assertSame('1000.00', $result->title->original_amount);
        $this->assertCount(1, $result->title->installments);
    }

    public function test_it_creates_receivable(): void
    {
        $result = $this->ingestion()->ingest($this->titleData(
            type: FinancialTitleType::Receivable,
            externalId: 'REC-1',
        ));

        $this->assertSame(FinancialTitleType::Receivable, $result->title->type);
        $this->assertSame('100.00', $result->title->total_amount);
    }

    public function test_same_source_and_external_id_is_idempotent(): void
    {
        $data = $this->titleData(externalId: 'DUPLICATE-1');

        $first = $this->ingestion()->ingest($data);
        $second = $this->ingestion()->ingest($data);

        $this->assertSame(IngestionDecision::Created, $first->decision);
        $this->assertSame(IngestionDecision::Ignored, $second->decision);
        $this->assertSame($first->title->id, $second->title->id);
        $this->assertDatabaseCount('financial_titles', 1);
        $this->assertDatabaseCount('title_installments', 1);
    }

    public function test_same_payload_with_another_request_key_is_still_ignored(): void
    {
        $first = $this->ingestion()->ingest($this->titleData(
            externalId: 'SAME-EVENT-1',
            idempotencyKey: 'request-original',
        ));
        $second = $this->ingestion()->ingest($this->titleData(
            externalId: 'SAME-EVENT-1',
            idempotencyKey: 'request-retry',
        ));

        $this->assertSame(IngestionDecision::Ignored, $second->decision);
        $this->assertSame($first->title->id, $second->title->id);
        $this->assertSame('request-original', $second->title->idempotency_key);
        $this->assertDatabaseCount('financial_titles', 1);
    }

    public function test_blank_manual_external_ids_are_stored_as_null_without_colliding(): void
    {
        $first = $this->ingestion()->ingest($this->titleData(
            sourceCode: 'MANUAL',
            externalId: '   ',
        ));
        $second = $this->ingestion()->ingest($this->titleData(
            sourceCode: 'MANUAL',
            externalId: '',
        ));

        $this->assertNull($first->title->external_id);
        $this->assertNull($second->title->external_id);
        $this->assertNotSame($first->title->id, $second->title->id);
        $this->assertDatabaseCount('financial_titles', 2);
    }

    public function test_external_source_requires_external_id(): void
    {
        $this->expectException(DomainException::class);
        $this->ingestion()->ingest($this->titleData(externalId: null));
    }

    public function test_changed_reingestion_updates_unsettled_title_without_duplication(): void
    {
        $this->ingestion()->ingest($this->titleData(externalId: 'UPDATE-1'));

        $updated = $this->ingestion()->ingest($this->titleData(
            externalId: 'UPDATE-1',
            originalAmount: '250.00',
            installmentCount: 2,
        ));

        $this->assertSame(IngestionDecision::Updated, $updated->decision);
        $this->assertSame('250.00', $updated->title->total_amount);
        $this->assertCount(2, $updated->title->installments);
        $this->assertDatabaseCount('financial_titles', 1);
    }

    public function test_changed_reingestion_is_rejected_after_any_settlement(): void
    {
        $title = $this->createTitle('UPDATE-BLOCKED');
        $this->settlements()->settle($title->id, '10.00', '2026-08-20');

        try {
            $this->ingestion()->ingest($this->titleData(
                externalId: 'UPDATE-BLOCKED',
                originalAmount: '200.00',
            ));
            $this->fail('A atualização de um título liquidado deveria ser rejeitada.');
        } catch (DomainException $exception) {
            // A recusa agora nomeia o campo financeiro que foi barrado, para
            // que o operador saiba o que a origem tentou mudar depois da baixa.
            $this->assertSame(
                'Título liquidado ou cancelado não pode ter o valor, o total alterado por reenvio.',
                $exception->getMessage(),
            );
        }

        $this->assertSame('100.00', $title->fresh()->total_amount);
        $this->assertSame('90.00', $title->fresh()->remainingAmount());
        $this->assertDatabaseCount('financial_titles', 1);
    }

    public function test_same_external_id_from_different_sources_can_coexist(): void
    {
        $this->ingestion()->ingest($this->titleData(sourceCode: 'AGROCOLITTI', externalId: '123'));
        $this->ingestion()->ingest($this->titleData(sourceCode: 'ACOP_FILES', externalId: '123'));

        $this->assertDatabaseCount('financial_titles', 2);
    }

    public function test_idempotency_key_with_different_payload_is_rejected(): void
    {
        $this->ingestion()->ingest($this->titleData(externalId: 'IDEM-1', idempotencyKey: 'request-1'));

        $this->expectException(DomainException::class);
        $this->ingestion()->ingest($this->titleData(
            externalId: 'IDEM-1',
            idempotencyKey: 'request-1',
            originalAmount: '101.00',
        ));
    }

    public function test_legacy_reference_requires_type_and_id_together(): void
    {
        $this->expectException(DomainException::class);

        $this->ingestion()->ingest($this->titleData(
            externalId: 'LEGACY-INVALID',
            legacyType: 'LANCAMENTO',
        ));
    }

    public function test_integral_settlement_closes_title_and_installment(): void
    {
        $title = $this->createTitle('SETTLE-FULL');
        $settlement = $this->settlements()->settle($title->id, '100.00', '2026-08-20');

        $this->assertSame(SettlementType::Payment, $settlement->type);
        $this->assertSame(TitleStatus::Settled, $title->fresh()->status);
        $this->assertSame(TitleStatus::Settled, $title->installments()->first()->status);
        $this->assertSame('0.00', $title->fresh()->remainingAmount());
    }

    public function test_partial_then_second_settlement_updates_remaining_balance(): void
    {
        $title = $this->createTitle('SETTLE-PARTIAL', FinancialTitleType::Receivable, '1000.00');

        $first = $this->settlements()->settle($title->id, '400.00', '2026-08-20');
        $this->assertSame(SettlementType::Receipt, $first->type);
        $this->assertSame(TitleStatus::PartiallySettled, $title->fresh()->status);
        $this->assertSame('600.00', $title->fresh()->remainingAmount());

        $this->settlements()->settle($title->id, '600.00', '2026-08-21');
        $this->assertSame(TitleStatus::Settled, $title->fresh()->status);
        $this->assertSame('0.00', $title->fresh()->remainingAmount());
        $this->assertDatabaseCount('title_settlements', 2);
    }

    public function test_settlement_replay_is_idempotent_even_after_title_is_closed(): void
    {
        $title = $this->createTitle('SETTLEMENT-IDEMPOTENT');
        $sourceId = SourceSystem::query()->where('code', 'AGROCOLITTI')->value('id');

        $first = $this->settlements()->settle(
            $title->id,
            '100.00',
            '2026-08-20',
            sourceSystemId: $sourceId,
            externalId: 'PAYMENT-1',
            idempotencyKey: 'settlement-request-1',
        );
        $second = $this->settlements()->settle(
            $title->id,
            '100.00',
            '2026-08-20',
            sourceSystemId: $sourceId,
            externalId: 'PAYMENT-1',
            idempotencyKey: 'settlement-request-1',
        );

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('title_settlements', 1);
        $this->assertSame(TitleStatus::Settled, $title->fresh()->status);
    }

    public function test_multi_installment_title_requires_installment_and_tracks_each_balance(): void
    {
        $title = $this->ingestion()->ingest($this->titleData(
            externalId: 'SETTLE-INSTALLMENTS',
            originalAmount: '100.00',
            installmentCount: 2,
        ))->title;

        try {
            $this->settlements()->settle($title->id, '10.00', '2026-08-20');
            $this->fail('A liquidação sem parcela deveria ter sido rejeitada.');
        } catch (DomainException $exception) {
            $this->assertSame('A parcela é obrigatória para liquidar um título parcelado.', $exception->getMessage());
        }

        [$first, $second] = $title->installments()->get()->all();
        $this->settlements()->settle($title->id, '25.00', '2026-08-20', $first->id);
        $this->assertSame(TitleStatus::PartiallySettled, $first->fresh()->status);
        $this->assertSame(TitleStatus::PartiallySettled, $title->fresh()->status);

        $this->settlements()->settle($title->id, '25.00', '2026-08-21', $first->id);
        $this->assertSame(TitleStatus::Settled, $first->fresh()->status);
        $this->assertSame(TitleStatus::PartiallySettled, $title->fresh()->status);

        $this->settlements()->settle($title->id, '50.00', '2026-09-30', $second->id);
        $this->assertSame(TitleStatus::Settled, $second->fresh()->status);
        $this->assertSame(TitleStatus::Settled, $title->fresh()->status);
        $this->assertSame('0.00', $title->fresh()->remainingAmount());
    }

    public function test_settlement_keys_without_source_are_rejected(): void
    {
        $title = $this->createTitle('SETTLEMENT-NO-SOURCE');

        $this->expectException(DomainException::class);
        $this->settlements()->settle(
            $title->id,
            '10.00',
            '2026-08-20',
            externalId: 'ORPHAN-KEY',
        );
    }

    public function test_composed_ingestion_rolls_back_when_audit_fails(): void
    {
        $this->app->instance(AuditEventRecorder::class, new class implements AuditEventRecorder
        {
            public function record(
                string $action,
                string $entityType,
                int|string $entityId,
                ?array $before,
                ?array $after,
                ?int $sourceSystemId,
                ?int $actorId,
                string $correlationId,
                ?int $integrationClientId = null,
            ): void {
                throw new RuntimeException('Falha de auditoria simulada.');
            }
        });

        try {
            $this->ingestion()->ingest($this->titleData(externalId: 'ROLLBACK-1', installmentCount: 3));
            $this->fail('A exceção esperada não foi lançada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha de auditoria simulada.', $exception->getMessage());
        }

        $this->assertDatabaseCount('financial_titles', 0);
        $this->assertDatabaseCount('title_installments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_composed_settlement_rolls_back_amount_and_status_when_audit_fails(): void
    {
        $title = $this->createTitle('SETTLEMENT-ROLLBACK');
        $this->app->instance(AuditEventRecorder::class, new class implements AuditEventRecorder
        {
            public function record(
                string $action,
                string $entityType,
                int|string $entityId,
                ?array $before,
                ?array $after,
                ?int $sourceSystemId,
                ?int $actorId,
                string $correlationId,
                ?int $integrationClientId = null,
            ): void {
                throw new RuntimeException('Falha de auditoria na liquidação.');
            }
        });

        try {
            $this->settlements()->settle($title->id, '100.00', '2026-08-20');
            $this->fail('A exceção esperada não foi lançada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha de auditoria na liquidação.', $exception->getMessage());
        }

        $this->assertDatabaseCount('title_settlements', 0);
        $this->assertSame(TitleStatus::Open, $title->fresh()->status);
        $this->assertSame(TitleStatus::Open, $title->installments()->first()->status);
    }

    private function ingestion(): TitleIngestionService
    {
        return $this->app->make(TitleIngestionService::class);
    }

    private function settlements(): SettlementService
    {
        return $this->app->make(SettlementService::class);
    }

    private function createTitle(
        string $externalId,
        FinancialTitleType $type = FinancialTitleType::Payable,
        string $amount = '100.00',
    ): FinancialTitle {
        return $this->ingestion()->ingest($this->titleData(
            externalId: $externalId,
            type: $type,
            originalAmount: $amount,
        ))->title;
    }

    private function titleData(
        string $sourceCode = 'AGROCOLITTI',
        ?string $externalId = 'EXT-1',
        FinancialTitleType $type = FinancialTitleType::Payable,
        string $originalAmount = '100.00',
        string $discountAmount = '0.00',
        string $additionAmount = '0.00',
        int $installmentCount = 1,
        ?string $idempotencyKey = null,
        ?string $legacyType = null,
        ?int $legacyId = null,
    ): TitleIngestionData {
        return new TitleIngestionData(
            sourceCode: $sourceCode,
            externalId: $externalId,
            type: $type,
            issueDate: '2026-08-13',
            dueDate: '2026-08-31',
            originalAmount: $originalAmount,
            discountAmount: $discountAmount,
            additionAmount: $additionAmount,
            documentNumber: 'DOC-1',
            installmentCount: $installmentCount,
            idempotencyKey: $idempotencyKey,
            legacyType: $legacyType,
            legacyId: $legacyId,
        );
    }
}
