<?php

namespace App\Models;

use App\Domain\Financial\Enums\SettlementStatus;
use App\Domain\Financial\Enums\SettlementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TitleSettlement extends Model
{
    protected $fillable = [
        'financial_title_id', 'title_installment_id', 'bank_account_id', 'settlement_date', 'amount', 'type', 'status',
        'source_system_id', 'external_id', 'idempotency_key', 'payload_hash', 'created_by',
        'correlation_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'immutable_date',
            'amount' => 'decimal:2',
            'type' => SettlementType::class,
            'status' => SettlementStatus::class,
            'metadata' => 'array',
        ];
    }

    public function financialTitle(): BelongsTo
    {
        return $this->belongsTo(FinancialTitle::class);
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(TitleInstallment::class, 'title_installment_id');
    }

    public function sourceSystem(): BelongsTo
    {
        return $this->belongsTo(SourceSystem::class);
    }

    /**
     * A conta bancária por onde o dinheiro efetivamente passou.
     *
     * Nulo quer dizer "ainda não se sabe" — o caso das liquidações vindas das
     * origens legadas, que não registram banco. Nunca quer dizer "a conta
     * padrão": ver ADR-017.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /** Fato financeiro confirmado que ainda não sabe por qual banco passou. */
    public function isAwaitingBankAccount(): bool
    {
        return $this->bank_account_id === null
            && $this->status === SettlementStatus::Confirmed;
    }
}
