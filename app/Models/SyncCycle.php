<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncCycle extends Model
{
    protected $table = 'sync_cycles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'source_read_completed_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isOk(): bool
    {
        return $this->status === 'OK';
    }

    /**
     * Conflito de regra: a origem tentou alterar campo protegido de título
     * liquidado/cancelado. O ciclo aplicou todo o resto — isto não é falha.
     */
    public function hasConflicts(): bool
    {
        return $this->status === 'CONFLICT' || (int) $this->conflict_count > 0;
    }

    /**
     * Falha técnica de verdade — a única que deve fazer a tarefa agendada
     * retornar erro e alguém ser chamado.
     */
    public function isFailure(): bool
    {
        return $this->status === 'ERROR';
    }

    /**
     * O ciclo terminou sem falha técnica? Um ciclo com conflitos conhecidos
     * terminou: aplicou o que podia e nomeou o que não podia.
     */
    public function finishedCleanly(): bool
    {
        return $this->isOk() || $this->hasConflicts();
    }

    /** @return array<string, int> */
    public function rejectionReasons(): array
    {
        if ($this->rejected_summary === null) {
            return [];
        }

        $decoded = json_decode((string) $this->rejected_summary, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function durationSeconds(): ?float
    {
        if ($this->finished_at === null) {
            return null;
        }

        return round($this->finished_at->getTimestamp() - $this->started_at->getTimestamp(), 1);
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
