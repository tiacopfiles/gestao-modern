<?php

namespace App\Domain\Banking;

use App\Domain\Banking\Enums\BankTransactionDirection;

final readonly class BankTransactionData
{
    public function __construct(
        public int $accountId,
        public int $sourceSystemId,
        public int $importBatchId,
        public string $externalId,
        public BankTransactionDirection $direction,
        public int|string $amount,
        public string $currency,
        public string $transactionDate,
        public string $descriptionOriginal,
        public ?string $postedAt = null,
        public ?string $documentNumber = null,
        public ?string $bankReference = null,
        public ?string $endToEndId = null,
        public ?string $counterpartyName = null,
        public ?string $counterpartyDocument = null,
        public int|string|null $balanceAfter = null,
        public ?string $rawHash = null,
    ) {}
}
