<?php

namespace App\Application\Reconciliation;

use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;

class ReconciliationFeature
{
    public function assertEnabled(): void
    {
        if (! config('reconciliation.v2_enabled', false)) {
            throw new ReconciliationRuleViolation(
                'RECONCILIATION_V2_DISABLED',
                'A nova conciliação está desabilitada pelo kill switch.',
            );
        }
    }
}
