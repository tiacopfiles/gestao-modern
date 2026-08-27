<?php

namespace App\Application\Reconciliation;

use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;

class ReconciliationMatchingFeature
{
    public function __construct(private readonly ReconciliationFeature $reconciliation) {}

    public function assertEnabled(): void
    {
        $this->reconciliation->assertEnabled();
        if (! config('reconciliation.matching_enabled', false)) {
            throw new ReconciliationRuleViolation('RECONCILIATION_MATCHING_DISABLED', 'As sugestões de matching estão desabilitadas pelo kill switch.');
        }
    }
}
