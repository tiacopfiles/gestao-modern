<?php

namespace App\Models;

use App\Domain\Reconciliation\Enums\BankOnlyKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Declaração de que uma transação do extrato é exclusivamente bancária.
 *
 * Explica o movimento sem inventar título para ele.
 */
class BankOnlyMovement extends Model
{
    protected $table = 'bank_only_movements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'kind' => BankOnlyKind::class,
            'classified_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }
}
