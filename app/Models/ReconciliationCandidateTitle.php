<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationCandidateTitle extends Model
{
    protected $fillable = ['reconciliation_candidate_id', 'financial_title_id', 'title_installment_id', 'proposed_amount'];

    protected function casts(): array
    {
        return ['proposed_amount' => 'decimal:2'];
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ReconciliationCandidate::class, 'reconciliation_candidate_id');
    }

    public function financialTitle(): BelongsTo
    {
        return $this->belongsTo(FinancialTitle::class);
    }

    public function titleInstallment(): BelongsTo
    {
        return $this->belongsTo(TitleInstallment::class);
    }
}
