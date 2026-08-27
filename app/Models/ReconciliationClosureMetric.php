<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationClosureMetric extends Model
{
    protected $fillable = [
        'reconciliation_closure_id', 'metric_key', 'metric_value', 'metric_value_text',
    ];

    protected function casts(): array
    {
        return [
            'metric_value' => 'decimal:4',
        ];
    }

    public function closure(): BelongsTo
    {
        return $this->belongsTo(ReconciliationClosure::class, 'reconciliation_closure_id');
    }
}
