<?php

namespace App\Domain\Banking\Enums;

enum ImportItemResult: string
{
    case Imported = 'IMPORTED';
    case Duplicate = 'DUPLICATE';
    case Rejected = 'REJECTED';
}
