<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationCandidateTransaction extends Model
{
    protected $fillable = ['reconciliation_candidate_id', 'bank_transaction_id', 'proposed_amount'];

    protected function casts(): array
    {
        return ['proposed_amount' => 'decimal:2'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ReconciliationCandidate::class, 'reconciliation_candidate_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
