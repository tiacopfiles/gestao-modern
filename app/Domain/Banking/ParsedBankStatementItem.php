<?php

namespace App\Domain\Banking;

final readonly class ParsedBankStatementItem
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $position,
        public string $rawHash,
        public ?string $externalId,
        public ?BankTransactionData $transaction,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    public function isRejected(): bool
    {
        return $this->transaction === null;
    }
}
