<?php

namespace App\Application\Reconciliation;

use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use Illuminate\Support\Facades\DB;

class ReconciliationAllocationQuery
{
    /**
     * @param  iterable<int>  $installmentIds
     * @return array<int, int>
     */
    public function confirmedTitleCents(iterable $installmentIds): array
    {
        $ids = $this->ids($installmentIds);
        if ($ids === []) {
            return [];
        }

        return DB::table('reconciliation_match_titles as allocation')
            ->join('reconciliation_matches as reconciliation_match', 'reconciliation_match.id', '=', 'allocation.reconciliation_match_id')
            ->where('reconciliation_match.status', ReconciliationMatchStatus::Confirmed->value)
            ->whereIn('allocation.title_installment_id', $ids)
            ->groupBy('allocation.title_installment_id')
            ->select('allocation.title_installment_id')
            ->selectRaw('SUM('.$this->wrap('allocation.allocated_amount').') AS allocated')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->title_installment_id => Money::toCents((string) $row->allocated),
            ])
            ->all();
    }

    /**
     * @param  iterable<int>  $transactionIds
     * @return array<int, int>
     */
    public function confirmedTransactionCents(iterable $transactionIds): array
    {
        $ids = $this->ids($transactionIds);
        if ($ids === []) {
            return [];
        }

        return DB::table('reconciliation_match_transactions as allocation')
            ->join('reconciliation_matches as reconciliation_match', 'reconciliation_match.id', '=', 'allocation.reconciliation_match_id')
            ->where('reconciliation_match.status', ReconciliationMatchStatus::Confirmed->value)
            ->whereIn('allocation.bank_transaction_id', $ids)
            ->groupBy('allocation.bank_transaction_id')
            ->select('allocation.bank_transaction_id')
            ->selectRaw('SUM('.$this->wrap('allocation.allocated_amount').') AS allocated')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->bank_transaction_id => Money::toCents((string) $row->allocated),
            ])
            ->all();
    }

    /**
     * Qualifica uma coluna com alias usando o grammar da conexão.
     *
     * Necessário porque o alias de tabela também recebe o prefixo de conexão
     * (`avt_`), enquanto trechos de SQL cru passam sem tradução. Sem isto,
     * `SUM(allocation.allocated_amount)` referencia um alias inexistente e a
     * confirmação de match falha em qualquer banco com prefixo configurado.
     */
    private function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap($column);
    }

    /**
     * @param  iterable<int>  $values
     * @return list<int>
     */
    private function ids(iterable $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        sort($ids, SORT_NUMERIC);

        return array_values($ids);
    }
}
