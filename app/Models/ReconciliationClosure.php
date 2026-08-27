<?php

namespace App\Models;

use App\Domain\Reconciliation\Closure\Enums\ReconciliationClosureStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationClosure extends Model
{
    protected $fillable = [
        'reconciliation_session_id', 'account_id', 'period_start', 'period_end',
        'sequence_number', 'status', 'schema_version', 'engine_version',
        'closure_hash', 'hash_algorithm', 'snapshot_payload', 'previous_closure_id',
        'closed_by', 'closed_at', 'reopened_by', 'reopened_at', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'status' => ReconciliationClosureStatus::class,
            'snapshot_payload' => 'array',
            'closed_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReconciliationSession::class, 'reconciliation_session_id');
    }

    public function previousClosure(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_closure_id');
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ReconciliationClosureMatch::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ReconciliationClosureException::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ReconciliationClosureMetric::class);
    }

    public function reopenings(): HasMany
    {
        return $this->hasMany(ReconciliationReopening::class);
    }
}
