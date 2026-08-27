<?php

namespace App\Application\Reconciliation;

use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;

class ReconciliationClosingFeature
{
    public function __construct(private readonly ReconciliationFeature $reconciliation) {}

    public function assertEnabled(): void
    {
        $this->reconciliation->assertEnabled();
        if (! config('reconciliation.closing_enabled', false)) {
            throw new ReconciliationRuleViolation('RECONCILIATION_CLOSING_DISABLED', 'O fechamento de conciliação está desabilitado pelo kill switch.');
        }
    }
}
