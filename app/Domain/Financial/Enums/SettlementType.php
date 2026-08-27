<?php

namespace App\Domain\Financial\Enums;

enum SettlementType: string
{
    case Payment = 'PAYMENT';
    case Receipt = 'RECEIPT';
    case Reversal = 'REVERSAL';
}
