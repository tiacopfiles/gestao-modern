<?php

namespace App\Domain\Reconciliation\Enums;

enum ReconciliationMatchStatus: string
{
    case Confirmed = 'CONFIRMED';
    case Voided = 'VOIDED';
}
