<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um título que a origem insiste em reenviar com uma mudança que a regra de
 * negócio recusa.
 *
 * Não é erro do sistema e não é rejeição de dado inválido: é divergência entre
 * duas verdades — a da origem, que foi editada, e a do Gestão, que já tem o
 * título liquidado ou cancelado e não pode reescrever o histórico financeiro.
 * Fica aqui até alguém decidir.
 */
class OriginSyncConflict extends Model
{
    protected $table = 'origin_sync_conflicts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function title(): BelongsTo
    {
        return $this->belongsTo(FinancialTitle::class, 'financial_title_id');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function label(): string
    {
        return match ($this->source_code) {
            'LEGACY_PAYABLE' => 'Contas a Pagar',
            'LEGACY_RECEIVABLE' => 'Contas a Receber',
            default => $this->source_code,
        };
    }
}
