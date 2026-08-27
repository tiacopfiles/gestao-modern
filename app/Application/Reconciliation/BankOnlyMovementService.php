<?php

namespace App\Application\Reconciliation;

use App\Domain\Reconciliation\Enums\BankOnlyKind;
use App\Models\BankOnlyMovement;
use App\Models\BankTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Declara que um movimento do extrato é exclusivamente bancário.
 *
 * Não cria título, não cria match, não altera a transação. Só registra — com
 * autor, data e tipo — que aquele valor está explicado e não é pendência.
 */
class BankOnlyMovementService
{
    /**
     * Sugestão de tipo a partir do texto do extrato.
     *
     * Os padrões vieram das três conciliações reais. É SUGESTÃO: quem confirma é
     * a pessoa, porque texto de extrato é livre e classificar errado esconde um
     * movimento que talvez precisasse de título.
     */
    public function suggest(string $descricao): ?BankOnlyKind
    {
        $t = Str::of($descricao)->lower()->ascii()->squish()->toString();

        return match (true) {
            str_contains($t, 'rend pago') || str_contains($t, 'rendimento') || str_contains($t, 'aplic aut') => BankOnlyKind::Rendimento,
            str_starts_with($t, 'tar ') || str_contains($t, 'tarifa') || str_contains($t, 'cesta') => BankOnlyKind::Tarifa,
            str_contains($t, 'transferencia') || str_contains($t, 'ted ') || str_contains($t, 'doc ') => BankOnlyKind::TransferenciaInterna,
            str_contains($t, 'iof') || str_contains($t, 'cpmf') || str_contains($t, 'imposto') => BankOnlyKind::TributoBancario,
            str_contains($t, 'estorno') => BankOnlyKind::EstornoBancario,
            default => null,
        };
    }

    public function classify(
        int $bankTransactionId,
        BankOnlyKind $kind,
        ?int $actorId,
        ?string $justification = null,
        ?string $correlationId = null,
    ): BankOnlyMovement {
        $justificativa = trim((string) $justification);

        if ($kind->requerJustificativa() && $justificativa === '') {
            throw new DomainException('Movimento classificado como "Outro" exige justificativa.');
        }

        return DB::transaction(function () use ($bankTransactionId, $kind, $actorId, $justificativa, $correlationId): BankOnlyMovement {
            $transacao = BankTransaction::query()->lockForUpdate()->find($bankTransactionId);

            if ($transacao === null) {
                throw new DomainException('Transação bancária inexistente.');
            }

            // Um movimento já conciliado com título não é "só bancário": marcar
            // assim daria duas explicações contraditórias para o mesmo dinheiro.
            if ($transacao->reconciliationAllocations()->exists()) {
                throw new DomainException(
                    'Esta transação já está conciliada com título, então não é um movimento exclusivamente bancário.'
                );
            }

            return BankOnlyMovement::updateOrCreate(
                ['bank_transaction_id' => $bankTransactionId],
                [
                    'kind' => $kind->value,
                    'justification' => $justificativa === '' ? null : $justificativa,
                    'classified_by' => $actorId,
                    'classified_at' => now(),
                    'correlation_id' => $correlationId ?? (string) Str::uuid(),
                ],
            );
        });
    }

    /** Movimentos do período que continuam sem explicação — nem título, nem classificação. */
    public function pending(int $accountId, string $from, string $to)
    {
        return BankTransaction::query()
            ->where('account_id', $accountId)
            ->whereBetween('transaction_date', [$from, $to])
            ->whereDoesntHave('reconciliationAllocations')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')
                    ->from('bank_only_movements')
                    ->whereColumn('bank_only_movements.bank_transaction_id', 'bank_transactions.id');
            })
            ->orderBy('transaction_date')
            ->get();
    }
}
