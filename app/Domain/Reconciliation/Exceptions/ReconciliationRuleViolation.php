<?php

namespace App\Domain\Reconciliation\Exceptions;

use DomainException;

class ReconciliationRuleViolation extends DomainException
{
    public function __construct(
        public readonly string $rule,
        string $message,
    ) {
        parent::__construct($message);
    }
}
