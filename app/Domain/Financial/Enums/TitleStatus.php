<?php

namespace App\Domain\Financial\Enums;

enum TitleStatus: string
{
    case Open = 'OPEN';
    case PartiallySettled = 'PARTIALLY_SETTLED';
    case Settled = 'SETTLED';
    case Cancelled = 'CANCELLED';
}
