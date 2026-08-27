<?php

namespace App\Domain\Reconciliation\Closure\Enums;

enum ReconciliationClosureStatus: string
{
    case Closed = 'CLOSED';
    case Reopened = 'REOPENED';
}
