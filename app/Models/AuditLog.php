<?php

namespace App\Models;

class AuditLog extends LegacyModel
{
    protected $table = 'logs';

    protected $primaryKey = 'id_log';
}
