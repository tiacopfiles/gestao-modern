<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationMatchTransaction extends Model
{
    protected $fillable = [
        'reconciliation_match_id', 'bank_transaction_id', 'allocated_amount',
    ];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(ReconciliationMatch::class, 'reconciliation_match_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
