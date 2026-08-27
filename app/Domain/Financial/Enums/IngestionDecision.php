<?php

namespace App\Domain\Financial\Enums;

enum IngestionDecision: string
{
    case Created = 'CREATED';
    case Updated = 'UPDATED';
    case Ignored = 'IGNORED';
}
