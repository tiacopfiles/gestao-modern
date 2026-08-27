<?php

namespace App\Domain\Financial\Enums;

enum FinancialTitleType: string
{
    case Payable = 'PAYABLE';
    case Receivable = 'RECEIVABLE';
}
