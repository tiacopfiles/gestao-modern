<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationReopening extends Model
{
    protected $fillable = [
        'reconciliation_closure_id', 'reopened_by', 'reopened_at', 'reason',
        'previous_status', 'resulting_session_status', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'reopened_at' => 'immutable_datetime',
        ];
    }

    public function closure(): BelongsTo
    {
        return $this->belongsTo(ReconciliationClosure::class, 'reconciliation_closure_id');
    }

    public function reopenedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
