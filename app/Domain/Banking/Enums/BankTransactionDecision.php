<?php

namespace App\Domain\Banking\Enums;

enum BankTransactionDecision: string
{
    case Created = 'CREATED';
    case Duplicate = 'DUPLICATE';
}
