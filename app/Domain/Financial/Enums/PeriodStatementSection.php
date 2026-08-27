<?php

namespace App\Domain\Financial\Enums;

/**
 * Em que parte do relatório a linha entra.
 *
 * As planilhas de conciliação têm dois blocos na mesma aba, e só o primeiro é
 * movimento de verdade:
 *
 *  - LEDGER: o extrato conciliado — data, valor e saldo corrido. É o que soma
 *    no saldo final e nos totais de entrada e saída;
 *  - PENDING: o rodapé da aba do mês corrente, onde ficam os títulos já
 *    conhecidos que ainda NÃO caíram no banco. Na planilha essas linhas vêm sem
 *    data e sem saldo justamente porque ainda não aconteceram; aqui elas
 *    carregam vencimento e valor, mas continuam fora de todo cálculo de saldo.
 *
 * Misturar os dois seria o mesmo erro que somar uma previsão ao extrato.
 */
enum PeriodStatementSection: string
{
    case Ledger = 'LEDGER';
    case Pending = 'PENDING';

    public function label(): string
    {
        return match ($this) {
            self::Ledger => 'Movimento',
            self::Pending => 'Em aberto',
        };
    }

    public function isPendente(): bool
    {
        return $this === self::Pending;
    }
}
