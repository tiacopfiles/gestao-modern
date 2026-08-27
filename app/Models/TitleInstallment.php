<?php

namespace App\Models;

use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TitleInstallment extends Model
{
    protected $fillable = ['financial_title_id', 'installment_number', 'due_date', 'amount', 'status'];

    protected function casts(): array
    {
        return [
            'due_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'status' => TitleStatus::class,
        ];
    }

    public function financialTitle(): BelongsTo
    {
        return $this->belongsTo(FinancialTitle::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(TitleSettlement::class);
    }

    public function reconciliationAllocations(): HasMany
    {
        return $this->hasMany(ReconciliationMatchTitle::class);
    }

    public function remainingCents(): int
    {
        $settled = $this->settlements()
            ->where('status', 'CONFIRMED')
            ->get(['type', 'amount'])
            ->sum(fn (TitleSettlement $settlement): int => $settlement->type->value === 'REVERSAL'
                ? -Money::toCents($settlement->amount)
                : Money::toCents($settlement->amount));

        return max(0, Money::toCents($this->amount) - $settled);
    }
}
