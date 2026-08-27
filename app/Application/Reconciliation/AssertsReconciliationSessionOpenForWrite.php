<?php

namespace App\Application\Reconciliation;

use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Models\ReconciliationSession;

trait AssertsReconciliationSessionOpenForWrite
{
    private function assertSessionOpenForWrite(ReconciliationSession $session): void
    {
        if ($session->status === ReconciliationSessionStatus::Closed) {
            throw new ReconciliationRuleViolation(
                'RECONCILIATION_SESSION_CLOSED',
                'A sessão está fechada. Reabra-a explicitamente antes de realizar esta ação.',
            );
        }
    }
}
