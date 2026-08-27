<?php

namespace App\Application\Banking;

use App\Contracts\AuditEventRecorder;
use App\Domain\Banking\BankImportResult;
use App\Domain\Banking\BankTransactionData;
use App\Domain\Banking\Enums\BankTransactionDecision;
use App\Domain\Banking\Enums\ImportBatchStatus;
use App\Domain\Banking\Enums\ImportItemResult;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\IntegrationClient;
use Illuminate\Support\Facades\DB;

class CanonicalBankTransactionService
{
    public function __construct(
        private readonly BankAccountValidator $accounts,
        private readonly BankTransactionIngestionService $transactions,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function ingest(
        BankTransactionData $input,
        IntegrationClient $client,
        string $correlationId,
    ): BankImportResult {
        $this->accounts->ensureExists($input->accountId);

        return DB::transaction(function () use ($input, $client, $correlationId): BankImportResult {
            $batch = ImportBatch::query()->create([
                'source_system_id' => $client->source_system_id,
                'integration_client_id' => $client->id,
                'account_id' => $input->accountId,
                'channel' => 'API',
                'format' => 'CANONICAL_API',
                'status' => ImportBatchStatus::Processing,
                'total_items' => 1,
                'correlation_id' => $correlationId,
                'started_at' => now(),
                'metadata' => ['contract' => 'api-v1'],
            ]);
            $this->recordBatchEvent('BANK_IMPORT_STARTED', $batch, $client);

            $data = new BankTransactionData(
                accountId: $input->accountId,
                sourceSystemId: $client->source_system_id,
                importBatchId: $batch->id,
                externalId: $input->externalId,
                direction: $input->direction,
                amount: $input->amount,
                currency: $input->currency,
                transactionDate: $input->transactionDate,
                descriptionOriginal: $input->descriptionOriginal,
                postedAt: $input->postedAt,
                documentNumber: $input->documentNumber,
                bankReference: $input->bankReference,
                endToEndId: $input->endToEndId,
                counterpartyName: $input->counterpartyName,
                counterpartyDocument: $input->counterpartyDocument,
                balanceAfter: $input->balanceAfter,
                rawHash: $input->rawHash,
            );
            $result = $this->transactions->ingest($data, $correlationId, $client->id);
            $itemResult = $result->decision === BankTransactionDecision::Created
                ? ImportItemResult::Imported
                : ImportItemResult::Duplicate;

            ImportBatchItem::query()->create([
                'import_batch_id' => $batch->id,
                'position' => 1,
                'external_id' => $result->transaction->external_id,
                'bank_transaction_id' => $result->transaction->id,
                'result' => $itemResult,
                'raw_hash' => $input->rawHash ?? hash('sha256', $result->transaction->payload_hash),
                'metadata' => ['identity_quality' => 'STRONG'],
            ]);

            $batch->update([
                'status' => ImportBatchStatus::Completed,
                'imported_items' => $itemResult === ImportItemResult::Imported ? 1 : 0,
                'duplicate_items' => $itemResult === ImportItemResult::Duplicate ? 1 : 0,
                'completed_at' => now(),
            ]);
            $this->recordBatchEvent('BANK_IMPORT_COMPLETED', $batch->fresh(), $client);

            return new BankImportResult($batch->fresh(['items', 'transactions']));
        }, 3);
    }

    private function recordBatchEvent(string $action, ImportBatch $batch, IntegrationClient $client): void
    {
        $this->audit->record(
            $action,
            ImportBatch::class,
            $batch->id,
            null,
            [
                'id' => $batch->id,
                'account_id' => $batch->account_id,
                'channel' => $batch->channel,
                'format' => $batch->format,
                'status' => $batch->status->value,
                'total_items' => $batch->total_items,
                'imported_items' => $batch->imported_items,
                'duplicate_items' => $batch->duplicate_items,
                'rejected_items' => $batch->rejected_items,
            ],
            $client->source_system_id,
            null,
            $batch->correlation_id,
            $client->id,
        );
    }
}
