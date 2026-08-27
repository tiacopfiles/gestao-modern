<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationClosureException extends Model
{
    protected $fillable = [
        'reconciliation_closure_id', 'reconciliation_exception_id',
        'captured_status', 'captured_type',
    ];

    public function closure(): BelongsTo
    {
        return $this->belongsTo(ReconciliationClosure::class, 'reconciliation_closure_id');
    }

    public function exception(): BelongsTo
    {
        return $this->belongsTo(ReconciliationException::class, 'reconciliation_exception_id');
    }
}
