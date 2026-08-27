<?php

namespace App\Models;

use App\Domain\Banking\Enums\BankTransactionDirection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankTransaction extends Model
{
    protected $fillable = [
        'account_id', 'source_system_id', 'import_batch_id', 'external_id',
        'identity_quality', 'direction', 'amount', 'currency', 'transaction_date',
        'posted_at', 'description_original', 'document_number', 'bank_reference',
        'end_to_end_id', 'counterparty_name', 'counterparty_document', 'balance_after',
        'payload_hash', 'raw_hash',
    ];

    protected $hidden = ['payload_hash', 'raw_hash'];

    protected function casts(): array
    {
        return [
            'direction' => BankTransactionDirection::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transaction_date' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function sourceSystem(): BelongsTo
    {
        return $this->belongsTo(SourceSystem::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function importItems(): HasMany
    {
        return $this->hasMany(ImportBatchItem::class);
    }

    public function reconciliationAllocations(): HasMany
    {
        return $this->hasMany(ReconciliationMatchTransaction::class);
    }
}
