<?php

namespace App\Domain\Reconciliation\Enums;

enum ReconciliationCandidateStatus: string
{
    case Pending = 'PENDING';
    case Accepted = 'ACCEPTED';
    case Rejected = 'REJECTED';
    case Stale = 'STALE';
}
