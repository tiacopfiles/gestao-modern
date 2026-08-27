<?php

namespace App\Domain\Financial;

use App\Domain\Financial\Enums\IngestionDecision;
use App\Models\FinancialTitle;

final readonly class IngestionResult
{
    public function __construct(
        public IngestionDecision $decision,
        public FinancialTitle $title,
    ) {}
}
