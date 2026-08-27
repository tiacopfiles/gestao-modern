<?php

namespace App\Models;

use App\Domain\Reconciliation\Enums\ReconciliationCandidateStatus;
use App\Domain\Reconciliation\Enums\ReconciliationCandidateType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationCandidate extends Model
{
    protected $fillable = [
        'reconciliation_session_id', 'reconciliation_match_id', 'type', 'status',
        'score', 'confidence', 'engine_version', 'signature_hash', 'evidence',
        'generated_by', 'generated_at', 'decided_by', 'decided_at',
        'decision_reason', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReconciliationCandidateType::class,
            'status' => ReconciliationCandidateStatus::class,
            'evidence' => 'array',
            'generated_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReconciliationSession::class, 'reconciliation_session_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(ReconciliationMatch::class, 'reconciliation_match_id');
    }

    public function titleAllocations(): HasMany
    {
        return $this->hasMany(ReconciliationCandidateTitle::class);
    }

    public function transactionAllocations(): HasMany
    {
        return $this->hasMany(ReconciliationCandidateTransaction::class);
    }
}
