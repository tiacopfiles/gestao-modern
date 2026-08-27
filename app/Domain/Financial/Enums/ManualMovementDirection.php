<?php

namespace App\Domain\Financial\Enums;

/**
 * Sentido de um movimento manual.
 *
 * O rótulo em português mora aqui para a tela nunca precisar imprimir o valor
 * cru — o usuário lê "Entrada" e "Saída", nunca "IN" e "OUT".
 */
enum ManualMovementDirection: string
{
    case In = 'IN';
    case Out = 'OUT';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entrada',
            self::Out => 'Saída',
        };
    }

    public function isEntrada(): bool
    {
        return $this === self::In;
    }
}
