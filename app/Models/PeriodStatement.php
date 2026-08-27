<?php

namespace App\Models;

use App\Domain\Financial\Enums\PeriodStatementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodStatement extends Model
{
    protected $table = 'period_statements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'generated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'closed_at' => 'datetime',
            'status' => PeriodStatementStatus::class,
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PeriodStatementLine::class)->orderBy('line_number');
    }

    /**
     * Linhas que alguém tirou desta conciliação por não terem passado por esta
     * conta bancária. Ficam guardadas para poder voltar.
     */
    public function exclusions(): HasMany
    {
        return $this->hasMany(PeriodStatementExclusion::class, 'period_statement_id');
    }

    public function account(): ?Conta
    {
        return Conta::query()->find($this->account_id);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }
}
