<?php

namespace App\Domain\Reconciliation\Enums;

enum ReconciliationExceptionType: string
{
    case NoCandidate = 'NO_CANDIDATE';
    case AmbiguousCandidates = 'AMBIGUOUS_CANDIDATES';
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case StrongIdentifierConflict = 'STRONG_IDENTIFIER_CONFLICT';
    case PartiallyReconciledRemainder = 'PARTIALLY_RECONCILED_REMAINDER';
    case MissingRequiredData = 'MISSING_REQUIRED_DATA';
}
