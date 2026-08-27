<?php

namespace App\Application\Banking;

use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use App\Models\BankTransaction;
use App\Models\ReconciliationMatchTransaction;

/**
 * Extrato operacional com saldo corrido, por conta e período.
 *
 * Reproduz — e melhora — a visão que o sistema antigo oferecia em
 * `/conciliacoes`: DATA | DOCUMENTO | HISTÓRICO | ENTRADA | SAÍDA | SALDO.
 *
 * Duas diferenças em relação ao legado, ambas deliberadas:
 *
 * 1. **A base é o fato bancário** (`bank_transactions`), não uma recomposição de
 *    títulos. O legado montava a lista somando lançamentos e recebimentos por
 *    data de pagamento, o que responde "o que eu registrei", não "o que o banco
 *    diz". Aqui o extrato é o que o banco diz, e o título aparece ao lado quando
 *    a conciliação já ligou os dois (ADR-009).
 *
 * 2. **Aritmética em centavos inteiros.** O legado acumulava saldo em `float`;
 *    aqui todo o cálculo é `int` e a formatação acontece só na borda.
 *
 * O saldo inicial é **informado pelo operador**, não deduzido: o domínio moderno
 * ainda não representa saldo bancário oficial, e essa é uma decisão de negócio
 * pendente (pergunta 9 da Fase 6). Deixar explícito evita apresentar um número
 * inventado como se fosse contábil.
 */
class BankLedgerService
{
    /**
     * @return array{
     *     lines: list<array<string, mixed>>,
     *     opening_cents: int,
     *     closing_cents: int,
     *     credits_cents: int,
     *     debits_cents: int,
     *     reconciled_cents: int,
     *     unreconciled_cents: int,
     *     count: int
     * }
     */
    public function build(
        int $accountId,
        string $from,
        string $to,
        int $openingCents = 0,
        ?string $direction = null,
        ?string $reconciled = null,
        ?string $term = null,
    ): array {
        $transactions = BankTransaction::query()
            ->where('account_id', $accountId)
            ->whereBetween('transaction_date', [$from, $to])
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $allocations = $this->confirmedAllocations($transactions->pluck('id')->all());

        $balance = $openingCents;
        $credits = 0;
        $debits = 0;
        $reconciledCents = 0;
        $lines = [];

        foreach ($transactions as $transaction) {
            $amountCents = Money::toCents((string) $transaction->amount);
            $isCredit = $transaction->direction->value === 'CREDIT';
            $signed = $isCredit ? $amountCents : -$amountCents;

            $allocated = $allocations[$transaction->id]['cents'] ?? 0;
            $titles = $allocations[$transaction->id]['titles'] ?? [];

            $status = match (true) {
                $allocated === 0 => 'NAO_CONCILIADO',
                $allocated >= $amountCents => 'CONCILIADO',
                default => 'PARCIAL',
            };

            // O saldo corrido acompanha TODOS os fatos do período, conciliados ou
            // não — é o saldo da conta, não o saldo do que já foi explicado.
            // Os filtros abaixo escondem linhas da visualização, mas nunca alteram
            // o saldo já acumulado; por isso são aplicados depois da soma.
            $balance += $signed;
            $isCredit ? $credits += $amountCents : $debits += $amountCents;
            $reconciledCents += $allocated;

            if ($direction !== null && $transaction->direction->value !== $direction) {
                continue;
            }
            if ($reconciled === 'yes' && $status !== 'CONCILIADO') {
                continue;
            }
            if ($reconciled === 'no' && $status === 'CONCILIADO') {
                continue;
            }
            if ($term !== null && $term !== '' && ! $this->matchesTerm($transaction, $titles, $term)) {
                continue;
            }

            $lines[] = [
                'date' => $transaction->transaction_date,
                'document' => $transaction->document_number ?: $transaction->external_id,
                'description' => $transaction->description_original,
                'origin' => $transaction->importBatch?->format ?? 'API',
                'credit_cents' => $isCredit ? $amountCents : 0,
                'debit_cents' => $isCredit ? 0 : $amountCents,
                'signed_cents' => $signed,
                'balance_cents' => $balance,
                'status' => $status,
                'titles' => $titles,
                'transaction_id' => $transaction->id,
            ];
        }

        return [
            'lines' => $lines,
            'opening_cents' => $openingCents,
            'closing_cents' => $balance,
            'credits_cents' => $credits,
            'debits_cents' => $debits,
            'reconciled_cents' => $reconciledCents,
            'unreconciled_cents' => $credits + $debits - $reconciledCents,
            'count' => $transactions->count(),
        ];
    }

    /**
     * Soma alocada por transação em matches CONFIRMADOS, com os títulos ligados.
     *
     * @param  list<int>  $transactionIds
     * @return array<int, array{cents: int, titles: list<string>}>
     */
    private function confirmedAllocations(array $transactionIds): array
    {
        if ($transactionIds === []) {
            return [];
        }

        $rows = ReconciliationMatchTransaction::query()
            ->with(['match.titleAllocations.financialTitle'])
            ->whereIn('bank_transaction_id', $transactionIds)
            ->whereHas('match', fn ($q) => $q->where('status', ReconciliationMatchStatus::Confirmed->value))
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row->bank_transaction_id;
            $result[$id] ??= ['cents' => 0, 'titles' => []];
            $result[$id]['cents'] += Money::toCents((string) $row->allocated_amount);

            foreach ($row->match->titleAllocations as $allocation) {
                $title = $allocation->financialTitle;
                if (! $title) {
                    continue;
                }
                $label = $title->document_number ?: $title->external_id ?: ('#'.$title->id);
                if (! in_array($label, $result[$id]['titles'], true)) {
                    $result[$id]['titles'][] = $label;
                }
            }
        }

        return $result;
    }

    /** @param list<string> $titles */
    private function matchesTerm(BankTransaction $transaction, array $titles, string $term): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $transaction->description_original,
            $transaction->document_number,
            $transaction->external_id,
            ...$titles,
        ])));

        return str_contains($haystack, mb_strtolower($term));
    }
}
