<?php

namespace App\Domain\Financial\Enums;

/**
 * Ciclo de vida da conciliação (Movimento do Período).
 *
 * ABERTA: pode receber "Atualizar" — novos movimentos entram, movimentos
 * corrigidos são refletidos, movimentos que deixaram de ser elegíveis saem.
 * FECHADA: imutável. Nenhuma atualização é aceita; é o retrato definitivo do
 * período.
 */
enum PeriodStatementStatus: string
{
    case Open = 'OPEN';
    case Closed = 'CLOSED';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Em andamento',
            self::Closed => 'Fechada',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Open;
    }
}
