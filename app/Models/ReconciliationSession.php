<?php

namespace App\Models;

use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationSession extends Model
{
    protected $fillable = [
        'account_id', 'period_start', 'period_end', 'status', 'created_by',
        'updated_by', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'status' => ReconciliationSessionStatus::class,
        ];
    }

    public function matches(): HasMany
    {
        return $this->hasMany(ReconciliationMatch::class);
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ReconciliationCandidate::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(ReconciliationException::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(ReconciliationClosure::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
