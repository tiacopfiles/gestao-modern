<?php

namespace App\Domain\Financial;

use App\Models\FinancialTitle;

final readonly class CancellationResult
{
    public function __construct(
        public FinancialTitle $title,
        public bool $alreadyCancelled,
    ) {}
}
