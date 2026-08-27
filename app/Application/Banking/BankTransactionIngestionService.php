<?php

namespace App\Application\Banking;

use App\Contracts\AuditEventRecorder;
use App\Domain\Banking\BankTransactionData;
use App\Domain\Banking\BankTransactionIngestionResult;
use App\Domain\Banking\Enums\BankTransactionDecision;
use App\Domain\Banking\Exceptions\BankTransactionIdentityConflict;
use App\Domain\Financial\Money;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\SourceSystem;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BankTransactionIngestionService
{
    public function __construct(
        private readonly BankAccountValidator $accounts,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function ingest(
        BankTransactionData $data,
        string $correlationId,
        ?int $integrationClientId = null,
    ): BankTransactionIngestionResult {
        $this->accounts->ensureExists($data->accountId);

        return DB::transaction(function () use ($data, $correlationId, $integrationClientId): BankTransactionIngestionResult {
            $source = SourceSystem::query()
                ->whereKey($data->sourceSystemId)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $source) {
                throw new DomainException('Sistema de origem bancária inexistente ou inativo.');
            }

            $batch = ImportBatch::query()->lockForUpdate()->find($data->importBatchId);
            if (! $batch || $batch->source_system_id !== $source->id || $batch->account_id !== $data->accountId) {
                throw new DomainException('O lote não pertence à mesma origem e conta da transação.');
            }

            $attributes = $this->normalize($data);
            $payloadHash = $this->payloadHash($attributes);
            $existing = BankTransaction::query()
                ->where('account_id', $data->accountId)
                ->where('source_system_id', $source->id)
                ->where('external_id', $attributes['external_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->payload_hash !== $payloadHash) {
                    throw new BankTransactionIdentityConflict(
                        'O identificador bancário já existe com conteúdo diferente para esta conta e origem.',
                    );
                }

                $this->audit->record(
                    'BANK_TRANSACTION_DUPLICATE',
                    BankTransaction::class,
                    $existing->id,
                    null,
                    $this->auditSnapshot($existing),
                    $source->id,
                    null,
                    $correlationId,
                    $integrationClientId,
                );

                return new BankTransactionIngestionResult(BankTransactionDecision::Duplicate, $existing);
            }

            $transaction = BankTransaction::query()->create($attributes + [
                'source_system_id' => $source->id,
                'import_batch_id' => $batch->id,
                'payload_hash' => $payloadHash,
            ]);

            $this->audit->record(
                'BANK_TRANSACTION_IMPORTED',
                BankTransaction::class,
                $transaction->id,
                null,
                $this->auditSnapshot($transaction),
                $source->id,
                null,
                $correlationId,
                $integrationClientId,
            );

            return new BankTransactionIngestionResult(BankTransactionDecision::Created, $transaction->fresh());
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(BankTransactionData $data): array
    {
        $externalId = $this->requiredString($data->externalId, 128, 'external_id');
        $description = $this->requiredString($data->descriptionOriginal, 10000, 'description');
        $amountCents = Money::toCents($data->amount);
        if ($amountCents <= 0) {
            throw new DomainException('O valor da transação bancária deve ser maior que zero.');
        }

        $currency = Str::upper(trim($data->currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('A moeda deve usar um código ISO de três letras.');
        }

        $transactionDate = $this->strictDate($data->transactionDate, 'transaction_date');
        $postedAt = $this->strictDateTime($data->postedAt);
        $balanceAfter = $data->balanceAfter === null
            ? null
            : Money::fromCents(Money::toCents($data->balanceAfter));

        if ($data->rawHash !== null && ! preg_match('/^[a-f0-9]{64}$/', $data->rawHash)) {
            throw new DomainException('O hash bruto da transação é inválido.');
        }

        return [
            'account_id' => $data->accountId,
            'external_id' => $externalId,
            'identity_quality' => 'STRONG',
            'direction' => $data->direction,
            'amount' => Money::fromCents($amountCents),
            'currency' => $currency,
            'transaction_date' => $transactionDate,
            'posted_at' => $postedAt,
            'description_original' => $description,
            'document_number' => $this->nullableString($data->documentNumber, 120, 'document_number'),
            'bank_reference' => $this->nullableString($data->bankReference, 191, 'bank_reference'),
            'end_to_end_id' => $this->nullableString($data->endToEndId, 191, 'end_to_end_id'),
            'counterparty_name' => $this->nullableString($data->counterpartyName, 191, 'counterparty.name'),
            'counterparty_document' => $this->nullableString($data->counterpartyDocument, 30, 'counterparty.document'),
            'balance_after' => $balanceAfter,
            'raw_hash' => $data->rawHash,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payloadHash(array $attributes): string
    {
        $normalized = $attributes;
        unset($normalized['raw_hash']);
        $normalized['direction'] = $normalized['direction']->value;
        ksort($normalized);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function auditSnapshot(BankTransaction $transaction): array
    {
        return [
            'id' => $transaction->id,
            'account_id' => $transaction->account_id,
            'source_system_id' => $transaction->source_system_id,
            'import_batch_id' => $transaction->import_batch_id,
            'external_id' => $transaction->external_id,
            'direction' => $transaction->direction->value,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
            'transaction_date' => $transaction->transaction_date->toDateString(),
        ];
    }

    private function strictDate(string $value, string $field): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw new DomainException("{$field} deve usar YYYY-MM-DD.");
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new DomainException("{$field} deve usar YYYY-MM-DD.");
        }

        return $value;
    }

    private function strictDateTime(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new DomainException('posted_at deve usar ISO-8601 com timezone.');
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new DomainException('posted_at deve conter uma data e hora ISO-8601 válida.');
        }
    }

    private function requiredString(string $value, int $maxLength, string $field): string
    {
        $normalized = trim($value);
        if ($normalized === '' || mb_strlen($normalized) > $maxLength) {
            throw new DomainException("{$field} deve ter entre 1 e {$maxLength} caracteres.");
        }

        return $normalized;
    }

    private function nullableString(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (mb_strlen($normalized) > $maxLength) {
            throw new DomainException("{$field} excede {$maxLength} caracteres.");
        }

        return $normalized;
    }
}
