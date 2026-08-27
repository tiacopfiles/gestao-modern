<?php

namespace App\Domain\Banking\Enums;

enum BankTransactionDirection: string
{
    case Credit = 'CREDIT';
    case Debit = 'DEBIT';
}
