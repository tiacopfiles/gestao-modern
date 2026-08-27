<?php

namespace App\Models;

use App\Domain\Financial\Enums\ManualMovementDirection;
use App\Domain\Financial\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Entrada ou saída lançada à mão no Gestão, sem origem em Contas a Pagar ou
 * Contas a Receber.
 */
class ManualMovement extends Model
{
    use SoftDeletes;

    protected $table = 'manual_movements';

    protected $fillable = [
        'account_id', 'bank_account_id', 'document_number', 'movement_date',
        'direction', 'amount', 'history',
        'category_id', 'notes', 'created_by', 'updated_by', 'correlation_id',
        'import_key',
    ];

    protected function casts(): array
    {
        return [
            'movement_date' => 'immutable_date',
            'direction' => ManualMovementDirection::class,
            'amount' => 'decimal:2',
        ];
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class, 'account_id');
    }

    /**
     * A conta bancária por onde o dinheiro passou.
     *
     * Nulo é o caso NORMAL, não um dado faltando: significa "a conta padrão da
     * empresa", que é o que a planilha assume no cabeçalho. Só se preenche
     * quando o movimento foge do padrão.
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function amountCents(): int
    {
        return Money::toCents((string) $this->amount);
    }

    /** Positivo para entrada, negativo para saída. */
    public function signedCents(): int
    {
        return $this->direction->isEntrada() ? $this->amountCents() : -$this->amountCents();
    }
}
