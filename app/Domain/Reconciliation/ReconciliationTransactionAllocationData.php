<?php

namespace App\Domain\Reconciliation;

final readonly class ReconciliationTransactionAllocationData
{
    public function __construct(
        public int $bankTransactionId,
        public string $amount,
    ) {}
}
