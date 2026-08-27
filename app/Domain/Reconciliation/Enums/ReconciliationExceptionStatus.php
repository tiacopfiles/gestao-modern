<?php

namespace App\Domain\Reconciliation\Enums;

enum ReconciliationExceptionStatus: string
{
    case Open = 'OPEN';
    case InReview = 'IN_REVIEW';
    case Resolved = 'RESOLVED';
    case Justified = 'JUSTIFIED';
}
