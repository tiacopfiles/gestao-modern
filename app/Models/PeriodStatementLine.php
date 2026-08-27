<?php

namespace App\Models;

use App\Domain\Financial\Enums\PeriodStatementSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodStatementLine extends Model
{
    protected $table = 'period_statement_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'movement_date' => 'date',
            'due_date' => 'date',
            'section' => PeriodStatementSection::class,
        ];
    }

    public function isPendente(): bool
    {
        return $this->section === PeriodStatementSection::Pending;
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(PeriodStatement::class, 'period_statement_id');
    }

    public function isEntrada(): bool
    {
        return $this->amount_in_cents !== null;
    }
}
