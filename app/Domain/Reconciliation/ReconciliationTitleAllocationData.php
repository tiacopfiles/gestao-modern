<?php

namespace App\Domain\Reconciliation;

final readonly class ReconciliationTitleAllocationData
{
    public function __construct(
        public ?int $financialTitleId,
        public ?int $titleInstallmentId,
        public string $amount,
    ) {}
}
