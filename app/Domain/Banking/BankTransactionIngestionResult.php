<?php

namespace App\Domain\Banking;

use App\Domain\Banking\Enums\BankTransactionDecision;
use App\Models\BankTransaction;

final readonly class BankTransactionIngestionResult
{
    public function __construct(
        public BankTransactionDecision $decision,
        public BankTransaction $transaction,
    ) {}
}
