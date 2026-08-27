<?php

namespace App\Domain\Financial;

use App\Models\PeriodStatement;

/**
 * O que uma chamada de "Atualizar conciliação" encontrou.
 */
final readonly class PeriodStatementRefreshResult
{
    public function __construct(
        public PeriodStatement $statement,
        public int $novos,
        public int $atualizados,
        public int $removidos,
    ) {}

    public function mudouAlgo(): bool
    {
        return $this->novos > 0 || $this->atualizados > 0 || $this->removidos > 0;
    }
}
