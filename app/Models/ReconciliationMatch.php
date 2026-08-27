<?php

namespace App\Models;

use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use App\Domain\Reconciliation\Enums\ReconciliationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReconciliationMatch extends Model
{
    protected $fillable = [
        'reconciliation_session_id', 'status', 'method', 'confirmed_by',
        'confirmed_at', 'voided_by', 'voided_at', 'void_reason', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReconciliationMatchStatus::class,
            'method' => ReconciliationMethod::class,
            'confirmed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReconciliationSession::class, 'reconciliation_session_id');
    }

    public function titleAllocations(): HasMany
    {
        return $this->hasMany(ReconciliationMatchTitle::class);
    }

    public function transactionAllocations(): HasMany
    {
        return $this->hasMany(ReconciliationMatchTransaction::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
