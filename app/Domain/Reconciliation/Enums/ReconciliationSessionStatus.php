<?php

namespace App\Domain\Reconciliation\Enums;

enum ReconciliationSessionStatus: string
{
    case Open = 'OPEN';
    case InReview = 'IN_REVIEW';
    case Closed = 'CLOSED';
    case Reopened = 'REOPENED';
}
