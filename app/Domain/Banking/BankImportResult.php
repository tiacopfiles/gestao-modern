<?php

namespace App\Domain\Banking;

use App\Models\ImportBatch;

final readonly class BankImportResult
{
    public function __construct(
        public ImportBatch $batch,
        public bool $duplicateFile = false,
    ) {}
}
