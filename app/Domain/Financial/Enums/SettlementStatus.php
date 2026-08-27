<?php

namespace App\Domain\Financial\Enums;

enum SettlementStatus: string
{
    case Confirmed = 'CONFIRMED';
    case Cancelled = 'CANCELLED';
}
