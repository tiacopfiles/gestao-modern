<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $fillable = [
        'actor_id', 'action', 'entity_type', 'entity_id', 'before_state', 'after_state',
        'source_system_id', 'integration_client_id', 'correlation_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
