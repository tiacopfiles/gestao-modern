<?php

namespace App\Application\Banking;

use App\Contracts\AuditEventRecorder;
use App\Contracts\BankStatementImporter;
use App\Domain\Banking\BankImportResult;
use App\Domain\Banking\BankTransactionData;
use App\Domain\Banking\Enums\BankTransactionDecision;
use App\Domain\Banking\Enums\ImportBatchStatus;
use App\Domain\Banking\Enums\ImportItemResult;
use App\Domain\Banking\Exceptions\BankImportInvalidFile;
use App\Domain\Banking\Exceptions\BankImportTooLarge;
use App\Domain\Banking\Exceptions\BankImportUnsupportedFormat;
use App\Domain\Banking\Exceptions\BankTransactionIdentityConflict;
use App\Domain\Banking\ParsedBankStatementItem;
use App\Models\ImportBatch;
use App\Models\ImportBatchItem;
use App\Models\IntegrationClient;
use App\Models\SourceSystem;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class OfxImportService
{
    public function __construct(
        private readonly BankAccountValidator $accounts,
        private readonly BankStatementImporter $importer,
        private readonly BankTransactionIngestionService $transactions,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function import(
        UploadedFile $file,
        int $accountId,
        IntegrationClient $client,
        string $correlationId,
    ): BankImportResult {
        $started = hrtime(true);
        $this->accounts->ensureExists($accountId);
        $filename = $this->safeFilename($file->getClientOriginalName());
        $size = $file->getSize();
        $maxBytes = max(1, (int) config('banking.ofx_max_bytes'));
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $fileHash = null;

        if ($file->isValid() && is_int($size) && $size > 0 && $size <= $maxBytes) {
            $realPath = $file->getRealPath();
            $fileHash = is_string($realPath) ? hash_file('sha256', $realPath) : null;
        }

        [$batch, $duplicateFile] = DB::transaction(function () use (
            $client,
            $accountId,
            $fileHash,
            $filename,
            $correlationId,
            $size,
        ): array {
            SourceSystem::query()->lockForUpdate()->findOrFail($client->source_system_id);

            if ($fileHash !== null) {
                $existing = ImportBatch::query()
                    ->where('source_system_id', $client->source_system_id)
                    ->where('account_id', $accountId)
                    ->where('file_hash', $fileHash)
                    ->whereIn('status', [
                        ImportBatchStatus::Received->value,
                        ImportBatchStatus::Processing->value,
                        ImportBatchStatus::Completed->value,
                        ImportBatchStatus::Partial->value,
                    ])
                    ->oldest('id')
                    ->first();

                if ($existing) {
                    return [$existing, true];
                }
            }

            $created = ImportBatch::query()->create([
                'source_system_id' => $client->source_system_id,
                'integration_client_id' => $client->id,
                'account_id' => $accountId,
                'channel' => 'FILE',
                'format' => 'OFX',
                'original_filename' => $filename,
                'file_hash' => $fileHash,
                'status' => ImportBatchStatus::Received,
                'correlation_id' => $correlationId,
                'started_at' => now(),
                'metadata' => ['file_size' => is_int($size) ? $size : null],
            ]);
            $this->recordBatchEvent('BANK_IMPORT_STARTED', $created, $client);

            return [$created, false];
        }, 3);

        if ($duplicateFile) {
            $this->recordBatchEvent('BANK_IMPORT_DUPLICATE', $batch, $client, $correlationId);
            $this->logBatch($batch, $client, $accountId, $correlationId, $started);

            return new BankImportResult($batch, true);
        }

        try {
            if (! $file->isValid() || ! is_int($size) || $size < 1) {
                throw new BankImportInvalidFile('O arquivo OFX está vazio ou não foi recebido corretamente.');
            }
            if ($size > $maxBytes) {
                throw new BankImportTooLarge("O arquivo OFX excede o limite configurado de {$maxBytes} bytes.");
            }
            if ($extension !== 'ofx') {
                throw new BankImportUnsupportedFormat('O upload deve usar extensão .ofx e conteúdo OFX válido.');
            }

            $realPath = $file->getRealPath();
            if (! is_string($realPath) || ! is_file($realPath)) {
                throw new BankImportInvalidFile('Não foi possível acessar o upload temporário com segurança.');
            }
            $contents = file_get_contents($realPath, false, null, 0, $maxBytes + 1);
            if (! is_string($contents) || strlen($contents) > $maxBytes) {
                throw new BankImportTooLarge("O arquivo OFX excede o limite configurado de {$maxBytes} bytes.");
            }

            $statement = $this->importer->parse($contents);
            $batch->update([
                'status' => ImportBatchStatus::Processing,
                'total_items' => count($statement->items),
                'period_start' => $statement->periodStart,
                'period_end' => $statement->periodEnd,
                'metadata' => [
                    'file_size' => $size,
                    'ofx_account' => $statement->accountMetadata,
                ],
            ]);

            foreach ($statement->items as $item) {
                $this->processItem($batch, $item, $client);
            }

            $this->finishBatch($batch, $client);
        } catch (BankImportInvalidFile|BankImportUnsupportedFormat|BankImportTooLarge $exception) {
            $this->failBatch($batch, $client, $this->failureCode($exception), $exception->getMessage());
            throw $exception;
        } catch (Throwable $exception) {
            $this->failBatch($batch, $client, 'BANK_IMPORT_FAILED', 'A importação foi interrompida por uma falha interna segura.');
            throw $exception;
        } finally {
            $this->logBatch($batch, $client, $accountId, $correlationId, $started);
        }

        return new BankImportResult($batch->fresh());
    }

    private function processItem(
        ImportBatch $batch,
        ParsedBankStatementItem $item,
        IntegrationClient $client,
    ): void {
        DB::transaction(function () use ($batch, $item, $client): void {
            if ($item->isRejected()) {
                $this->storeItem($batch, $item, ImportItemResult::Rejected, null, $item->errorCode, $item->errorMessage);

                return;
            }

            /** @var BankTransactionData $parsed */
            $parsed = $item->transaction;
            $data = new BankTransactionData(
                accountId: $batch->account_id,
                sourceSystemId: $client->source_system_id,
                importBatchId: $batch->id,
                externalId: $parsed->externalId,
                direction: $parsed->direction,
                amount: $parsed->amount,
                currency: $parsed->currency,
                transactionDate: $parsed->transactionDate,
                descriptionOriginal: $parsed->descriptionOriginal,
                postedAt: $parsed->postedAt,
                documentNumber: $parsed->documentNumber,
                bankReference: $parsed->bankReference,
                endToEndId: $parsed->endToEndId,
                counterpartyName: $parsed->counterpartyName,
                counterpartyDocument: $parsed->counterpartyDocument,
                balanceAfter: $parsed->balanceAfter,
                rawHash: $item->rawHash,
            );

            try {
                $result = $this->transactions->ingest($data, $batch->correlation_id, $client->id);
                $itemResult = $result->decision === BankTransactionDecision::Created
                    ? ImportItemResult::Imported
                    : ImportItemResult::Duplicate;
                $this->storeItem($batch, $item, $itemResult, $result->transaction->id);
            } catch (BankTransactionIdentityConflict $exception) {
                $this->storeItem(
                    $batch,
                    $item,
                    ImportItemResult::Rejected,
                    null,
                    'BANK_TRANSACTION_ID_CONFLICT',
                    $exception->getMessage(),
                );
            } catch (DomainException $exception) {
                $this->storeItem(
                    $batch,
                    $item,
                    ImportItemResult::Rejected,
                    null,
                    'BANK_TRANSACTION_REJECTED',
                    $exception->getMessage(),
                );
            }
        }, 3);
    }

    private function storeItem(
        ImportBatch $batch,
        ParsedBankStatementItem $item,
        ImportItemResult $result,
        ?int $transactionId,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): void {
        DB::transaction(fn () => ImportBatchItem::query()->create([
            'import_batch_id' => $batch->id,
            'position' => $item->position,
            'external_id' => $item->externalId,
            'bank_transaction_id' => $transactionId,
            'result' => $result,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'raw_hash' => $item->rawHash,
            'metadata' => $item->metadata + ['identity_quality' => $item->externalId ? 'STRONG' : 'INSUFFICIENT'],
        ]), 3);
    }

    private function finishBatch(ImportBatch $batch, IntegrationClient $client): void
    {
        $counts = $batch->items()
            ->selectRaw('result, COUNT(*) AS aggregate')
            ->groupBy('result')
            ->pluck('aggregate', 'result');
        $imported = (int) ($counts[ImportItemResult::Imported->value] ?? 0);
        $duplicate = (int) ($counts[ImportItemResult::Duplicate->value] ?? 0);
        $rejected = (int) ($counts[ImportItemResult::Rejected->value] ?? 0);
        $status = match (true) {
            $rejected === 0 => ImportBatchStatus::Completed,
            $imported + $duplicate > 0 => ImportBatchStatus::Partial,
            default => ImportBatchStatus::Failed,
        };

        $batch->update([
            'status' => $status,
            'imported_items' => $imported,
            'duplicate_items' => $duplicate,
            'rejected_items' => $rejected,
            'completed_at' => now(),
            'failure_code' => $status === ImportBatchStatus::Failed ? 'BANK_IMPORT_ALL_ITEMS_REJECTED' : null,
            'failure_summary' => $status === ImportBatchStatus::Failed ? 'Todas as linhas do arquivo foram rejeitadas.' : null,
        ]);

        $action = match ($status) {
            ImportBatchStatus::Completed => 'BANK_IMPORT_COMPLETED',
            ImportBatchStatus::Partial => 'BANK_IMPORT_PARTIAL',
            default => 'BANK_IMPORT_FAILED',
        };
        $this->recordBatchEvent($action, $batch->fresh(), $client);
    }

    private function failBatch(
        ImportBatch $batch,
        IntegrationClient $client,
        string $failureCode,
        string $summary,
    ): void {
        $counts = $batch->items()->selectRaw('result, COUNT(*) AS aggregate')->groupBy('result')->pluck('aggregate', 'result');
        $batch->update([
            'status' => ImportBatchStatus::Failed,
            'imported_items' => (int) ($counts[ImportItemResult::Imported->value] ?? 0),
            'duplicate_items' => (int) ($counts[ImportItemResult::Duplicate->value] ?? 0),
            'rejected_items' => (int) ($counts[ImportItemResult::Rejected->value] ?? 0),
            'failure_code' => $failureCode,
            'failure_summary' => mb_substr($summary, 0, 1000),
            'completed_at' => now(),
        ]);
        $this->recordBatchEvent('BANK_IMPORT_FAILED', $batch->fresh(), $client);
    }

    private function recordBatchEvent(
        string $action,
        ImportBatch $batch,
        IntegrationClient $client,
        ?string $correlationId = null,
    ): void {
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
                'failure_code' => $batch->failure_code,
            ],
            $client->source_system_id,
            null,
            $correlationId ?? $batch->correlation_id,
            $client->id,
        );
    }

    private function failureCode(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof BankImportTooLarge => 'BANK_IMPORT_TOO_LARGE',
            $exception instanceof BankImportUnsupportedFormat => 'BANK_IMPORT_UNSUPPORTED_FORMAT',
            default => 'BANK_IMPORT_INVALID_FILE',
        };
    }

    private function safeFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));
        $basename = preg_replace('/[\x00-\x1F\x7F]/u', '', $basename) ?: 'statement.ofx';

        return mb_substr($basename, 0, 191);
    }

    private function logBatch(
        ImportBatch $batch,
        IntegrationClient $client,
        int $accountId,
        string $correlationId,
        int $started,
    ): void {
        $fresh = $batch->fresh();
        Log::info('bank_import_processed', [
            'correlation_id' => $correlationId,
            'batch_id' => $batch->id,
            'integration_client_id' => $client->id,
            'source_system' => $client->sourceSystem?->code,
            'account_id' => $accountId,
            'format' => 'OFX',
            'status' => $fresh?->status->value,
            'total' => $fresh?->total_items,
            'imported' => $fresh?->imported_items,
            'duplicate' => $fresh?->duplicate_items,
            'rejected' => $fresh?->rejected_items,
            'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2),
        ]);
    }
}
