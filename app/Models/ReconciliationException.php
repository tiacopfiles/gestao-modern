<?php

namespace App\Models;

use App\Domain\Reconciliation\Enums\ReconciliationExceptionStatus;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationException extends Model
{
    protected $fillable = [
        'reconciliation_session_id', 'financial_title_id', 'title_installment_id',
        'bank_transaction_id', 'type', 'status', 'amount', 'difference_amount',
        'evidence', 'engine_version', 'signature_hash', 'generated_by', 'generated_at',
        'resolved_by', 'resolved_at', 'resolution_reason', 'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReconciliationExceptionType::class,
            'status' => ReconciliationExceptionStatus::class,
            'amount' => 'decimal:2', 'difference_amount' => 'decimal:2',
            'evidence' => 'array', 'generated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ReconciliationSession::class, 'reconciliation_session_id');
    }

    public function financialTitle(): BelongsTo
    {
        return $this->belongsTo(FinancialTitle::class);
    }

    public function titleInstallment(): BelongsTo
    {
        return $this->belongsTo(TitleInstallment::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
