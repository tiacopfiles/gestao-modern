<?php

namespace App\Domain\Banking\Enums;

enum ImportBatchStatus: string
{
    case Received = 'RECEIVED';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
}
