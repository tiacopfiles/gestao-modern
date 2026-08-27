<?php

namespace App\Models;

use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinancialTitle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type', 'source_system_id', 'external_id', 'idempotency_key', 'payload_hash',
        'party_type', 'party_id', 'party_name', 'document_number', 'issue_date', 'due_date',
        'original_amount', 'discount_amount', 'addition_amount', 'total_amount', 'currency',
        'account_id', 'category_id', 'cost_center_id', 'status', 'notes', 'legacy_type', 'legacy_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => FinancialTitleType::class,
            'status' => TitleStatus::class,
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'original_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'addition_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function sourceSystem(): BelongsTo
    {
        return $this->belongsTo(SourceSystem::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(TitleInstallment::class)->orderBy('installment_number');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(TitleSettlement::class);
    }

    public function cancellation(): HasOne
    {
        return $this->hasOne(TitleCancellation::class);
    }

    public function reconciliationAllocations(): HasMany
    {
        return $this->hasMany(ReconciliationMatchTitle::class);
    }

    /**
     * Quanto já foi liquidado, em centavos. REVERSAL subtrai.
     *
     * Usa as liquidações já carregadas quando existem. Chamar `settlements()`
     * (o relacionamento) em vez de `settlements` (a coleção) dispara uma
     * consulta nova a cada título, mesmo depois de um `with('settlements')` —
     * o eager load era pago e ignorado. Na lista de títulos isso somava 25
     * consultas por página; era a mesma armadilha que fazia o dashboard levar
     * 13 segundos.
     *
     * O cálculo é idêntico nos dois caminhos: só entram liquidações CONFIRMED e
     * o estorno entra com sinal negativo.
     */
    public function settledCents(): int
    {
        $liquidacoes = $this->relationLoaded('settlements')
            // Já carregadas: filtra em memória, comparando pelo `value` do enum.
            ? $this->settlements->filter(
                fn (TitleSettlement $settlement): bool => $settlement->status->value === 'CONFIRMED',
            )
            // Não carregadas: o filtro continua no SQL, como sempre foi. Não é
            // só economia de linhas — o banco aceita status fora do enum, e
            // trazer uma dessas linhas para o PHP estoura no cast. Filtrando
            // antes do `get`, ela nunca chega aqui.
            : $this->settlements()->where('status', 'CONFIRMED')->get(['type', 'amount']);

        return $liquidacoes
            ->sum(fn (TitleSettlement $settlement): int => $settlement->type->value === 'REVERSAL'
                ? -Money::toCents($settlement->amount)
                : Money::toCents($settlement->amount));
    }

    public function remainingCents(): int
    {
        return max(0, Money::toCents($this->total_amount) - $this->settledCents());
    }

    public function remainingAmount(): string
    {
        return Money::fromCents($this->remainingCents());
    }
}
