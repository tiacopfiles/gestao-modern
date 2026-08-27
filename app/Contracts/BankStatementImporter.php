<?php

namespace App\Contracts;

use App\Domain\Banking\ParsedBankStatement;

interface BankStatementImporter
{
    public function parse(string $contents): ParsedBankStatement;
}
