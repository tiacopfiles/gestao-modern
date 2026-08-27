<?php

namespace App\Application\Reconciliation;

use App\Domain\Banking\Enums\BankTransactionDirection;
use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Enums\ReconciliationCandidateStatus;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionStatus;
use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use App\Models\BankTransaction;
use App\Models\ReconciliationCandidate;
use App\Models\ReconciliationSession;

class ReconciliationClosureSnapshotBuilder
{
    public const SCHEMA_VERSION = 'closure-snapshot-v1';

    /**
     * @return array{payload: array<string, mixed>, metrics: array<string, string>}
     */
    public function build(ReconciliationSession $session): array
    {
        $matches = $session->matches()->with(['titleAllocations', 'transactionAllocations'])->orderBy('id')->get();
        $exceptions = $session->exceptions()->orderBy('id')->get();

        $acceptedMatchIds = ReconciliationCandidate::query()
            ->where('reconciliation_session_id', $session->id)
            ->where('status', ReconciliationCandidateStatus::Accepted->value)
            ->whereNotNull('reconciliation_match_id')
            ->pluck('reconciliation_match_id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $matchRows = [];
        $matchedCents = 0;
        $manualCount = 0;
        $assistedCount = 0;
        foreach ($matches as $match) {
            $totalCents = (int) $match->transactionAllocations->sum(
                fn ($allocation): int => Money::toCents((string) $allocation->allocated_amount),
            );
            $matchRows[] = [
                'match_id' => $match->id,
                'status' => $match->status->value,
                'total_amount' => Money::fromCents($totalCents),
            ];
            if ($match->status === ReconciliationMatchStatus::Confirmed) {
                $matchedCents += $totalCents;
                if (in_array($match->id, $acceptedMatchIds, true)) {
                    $assistedCount++;
                } else {
                    $manualCount++;
                }
            }
        }
        usort($matchRows, fn (array $a, array $b): int => $a['match_id'] <=> $b['match_id']);

        $exceptionRows = [];
        $openCount = 0;
        $justifiedCount = 0;
        foreach ($exceptions as $exception) {
            $exceptionRows[] = [
                'exception_id' => $exception->id,
                'status' => $exception->status->value,
                'type' => $exception->type->value,
            ];
            if (in_array($exception->status, [ReconciliationExceptionStatus::Open, ReconciliationExceptionStatus::InReview], true)) {
                $openCount++;
            }
            if ($exception->status === ReconciliationExceptionStatus::Justified) {
                $justifiedCount++;
            }
        }
        usort($exceptionRows, fn (array $a, array $b): int => $a['exception_id'] <=> $b['exception_id']);

        $transactions = BankTransaction::query()
            ->where('account_id', $session->account_id)
            ->whereBetween('transaction_date', [$session->period_start, $session->period_end])
            ->get(['id', 'amount', 'direction']);
        $creditCents = 0;
        $debitCents = 0;
        foreach ($transactions as $transaction) {
            $cents = Money::toCents((string) $transaction->amount);
            if ($transaction->direction === BankTransactionDirection::Credit) {
                $creditCents += $cents;
            } else {
                $debitCents += $cents;
            }
        }
        $unreconciledCents = max(0, ($creditCents + $debitCents) - $matchedCents);

        $titlesCount = (int) $session->matches()
            ->where('reconciliation_matches.status', ReconciliationMatchStatus::Confirmed->value)
            ->join('reconciliation_match_titles', 'reconciliation_match_titles.reconciliation_match_id', '=', 'reconciliation_matches.id')
            ->distinct()
            ->count('reconciliation_match_titles.financial_title_id');

        $metrics = [
            'bank_transactions_count' => (string) $transactions->count(),
            'credit_total' => Money::fromCents($creditCents),
            'debit_total' => Money::fromCents($debitCents),
            'titles_count' => (string) $titlesCount,
            'reconciled_amount' => Money::fromCents($matchedCents),
            'unreconciled_amount' => Money::fromCents($unreconciledCents),
            'matches_manual_count' => (string) $manualCount,
            'matches_assisted_count' => (string) $assistedCount,
            'exceptions_justified_count' => (string) $justifiedCount,
            'exceptions_open_count' => (string) $openCount,
        ];
        $metricRows = [];
        foreach ($metrics as $key => $value) {
            $metricRows[] = ['metric_key' => $key, 'metric_value' => $value];
        }
        usort($metricRows, fn (array $a, array $b): int => $a['metric_key'] <=> $b['metric_key']);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'engine_version' => (string) config('reconciliation_matching.engine_version', 'rules-v1'),
            'account_id' => (int) $session->account_id,
            'reconciliation_session_id' => $session->id,
            'period_start' => $session->period_start->format('Y-m-d'),
            'period_end' => $session->period_end->format('Y-m-d'),
            'matches' => $matchRows,
            'exceptions' => $exceptionRows,
            'metrics' => $metricRows,
        ];

        return ['payload' => $payload, 'metrics' => $metrics];
    }
}
