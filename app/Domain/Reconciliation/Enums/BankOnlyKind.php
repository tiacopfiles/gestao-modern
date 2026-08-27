<?php

namespace App\Domain\Reconciliation\Enums;

/**
 * Movimento que existe SÓ no banco, sem título correspondente — e sem defeito.
 *
 * Nas três conciliações reais isso é grande: só em janeiro/2026 a Acop Files tem
 * R$ 230.151,24 assim, e a Global Box R$ 481.088,73 em saídas. Nenhum desses
 * valores passa por Contas a Pagar ou Contas a Receber, e nunca vai passar.
 *
 * Classificar não é o mesmo que criar título: o movimento continua existindo só
 * do lado do banco. O que a classificação faz é tirá-lo da fila de "título não
 * encontrado" — porque ele não está faltando, ele não existe mesmo.
 */
enum BankOnlyKind: string
{
    /** Tarifa cobrada pelo banco. "TAR Cobrança EXP", "Tar Conta Certa 12/19". */
    case Tarifa = 'TARIFA';

    /** Rendimento de aplicação automática. "Rend Pago Aplic Aut APR". */
    case Rendimento = 'RENDIMENTO';

    /** Transferência entre contas do próprio grupo. Sai de uma, entra em outra. */
    case TransferenciaInterna = 'TRANSFERENCIA_INTERNA';

    /** Imposto/encargo debitado direto pelo banco, sem título. */
    case TributoBancario = 'TRIBUTO_BANCARIO';

    /** Estorno/ajuste feito pelo próprio banco. */
    case EstornoBancario = 'ESTORNO_BANCARIO';

    /** Legítimo, mas fora dos padrões acima. Exige justificativa escrita. */
    case Outro = 'OUTRO';

    public function label(): string
    {
        return match ($this) {
            self::Tarifa => 'Tarifa bancária',
            self::Rendimento => 'Rendimento de aplicação',
            self::TransferenciaInterna => 'Transferência entre contas',
            self::TributoBancario => 'Tributo debitado pelo banco',
            self::EstornoBancario => 'Estorno do banco',
            self::Outro => 'Outro movimento bancário',
        };
    }

    /** Só "Outro" exige justificativa: os demais se explicam pelo próprio tipo. */
    public function requerJustificativa(): bool
    {
        return $this === self::Outro;
    }
}
