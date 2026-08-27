<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Linha que a conciliação puxou e que não passou por aquela conta bancária.
 *
 * Excluir aqui não apaga nada: o título, a liquidação e o movimento manual
 * continuam intactos e visíveis nas telas deles. A linha só deixa de contar
 * naquele extrato.
 */
class PeriodStatementExclusion extends Model
{
    protected $table = 'period_statement_exclusions';

    protected $guarded = [];

    public function statement(): BelongsTo
    {
        return $this->belongsTo(PeriodStatement::class, 'period_statement_id');
    }

    /**
     * A mesma chave que `PeriodStatementService` usa para identificar uma linha
     * — é por ela que a exclusão encontra o que precisa tirar.
     */
    public function chave(): string
    {
        return $this->manual_movement_id !== null
            ? 'manual:'.$this->manual_movement_id
            : 'settlement:'.$this->title_settlement_id;
    }
}
