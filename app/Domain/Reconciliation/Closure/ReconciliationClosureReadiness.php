<?php

namespace App\Domain\Reconciliation\Closure;

final readonly class ReconciliationClosureReadiness
{
    /**
     * @param  list<array{code: string, message: string}>  $blockers
     * @param  list<array{code: string, message: string}>  $warnings
     */
    public function __construct(
        public bool $ready,
        public array $blockers,
        public array $warnings,
    ) {}
}
