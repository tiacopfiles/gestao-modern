<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationMatchTitle extends Model
{
    protected $fillable = [
        'reconciliation_match_id', 'financial_title_id', 'title_installment_id', 'allocated_amount',
    ];

    protected function casts(): array
    {
        return ['allocated_amount' => 'decimal:2'];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(ReconciliationMatch::class, 'reconciliation_match_id');
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
