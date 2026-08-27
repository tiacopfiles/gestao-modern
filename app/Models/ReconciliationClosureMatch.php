<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationClosureMatch extends Model
{
    protected $fillable = [
        'reconciliation_closure_id', 'reconciliation_match_id',
        'captured_status', 'captured_total_amount',
    ];

    protected function casts(): array
    {
        return [
            'captured_total_amount' => 'decimal:2',
        ];
    }

    public function closure(): BelongsTo
    {
        return $this->belongsTo(ReconciliationClosure::class, 'reconciliation_closure_id');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(ReconciliationMatch::class, 'reconciliation_match_id');
    }
}
