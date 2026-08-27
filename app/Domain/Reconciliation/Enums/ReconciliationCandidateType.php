<?php

namespace App\Domain\Reconciliation\Enums;

enum ReconciliationCandidateType: string
{
    case OneToOne = 'ONE_TO_ONE';
    case OneToMany = 'ONE_TO_MANY';
    case ManyToOne = 'MANY_TO_ONE';
}
