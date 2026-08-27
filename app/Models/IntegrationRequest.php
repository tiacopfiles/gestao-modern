<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationRequest extends Model
{
    public const STATUS_PROCESSING = 'PROCESSING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_FAILED = 'FAILED';

    protected $fillable = [
        'integration_client_id', 'source_system_id', 'idempotency_key_hash',
        'idempotency_key_prefix', 'request_method', 'request_path', 'request_hash',
        'status', 'response_status', 'response_body', 'failure_code', 'correlation_id',
        'started_at', 'completed_at',
    ];

    protected $hidden = ['idempotency_key_hash'];

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class, 'integration_client_id');
    }

    public function sourceSystem(): BelongsTo
    {
        return $this->belongsTo(SourceSystem::class);
    }
}
