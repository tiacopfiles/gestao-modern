<?php

namespace App\Application\Reconciliation;

use App\Contracts\AuditEventRecorder;
use App\Domain\Banking\Enums\BankTransactionDirection;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Enums\ReconciliationCandidateStatus;
use App\Domain\Reconciliation\Enums\ReconciliationCandidateType;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionStatus;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionType;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Models\BankTransaction;
use App\Models\ReconciliationCandidate;
use App\Models\ReconciliationCandidateTitle;
use App\Models\ReconciliationCandidateTransaction;
use App\Models\ReconciliationException;
use App\Models\ReconciliationSession;
use App\Models\TitleInstallment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationMatchingEngine
{
    use AssertsReconciliationSessionOpenForWrite;

    public function __construct(
        private readonly ReconciliationMatchingFeature $feature,
        private readonly ReconciliationAllocationQuery $allocations,
        private readonly ReconciliationCandidateScorer $scorer,
        private readonly AuditEventRecorder $audit,
    ) {}

    /** @return array{candidates: int, exceptions: int, stale: int, engine_version: string} */
    public function generate(int $sessionId, int $actorId, string $correlationId): array
    {
        $this->feature->assertEnabled();
        if ($actorId < 1) {
            throw new ReconciliationRuleViolation('RECONCILIATION_ACTOR_REQUIRED', 'O usuário responsável é obrigatório.');
        }
        $session = ReconciliationSession::query()->find($sessionId);
        if (! $session) {
            throw new ReconciliationRuleViolation('RECONCILIATION_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
        }
        $this->assertSessionOpenForWrite($session);

        $limit = (int) config('reconciliation_matching.max_candidate_pool', 200);
        $transactions = BankTransaction::query()
            ->where('account_id', $session->account_id)
            ->whereBetween('transaction_date', [$session->period_start, $session->period_end])
            ->orderBy('transaction_date')->orderBy('id')->limit($limit)->get();
        $titles = TitleInstallment::query()->with('financialTitle')
            ->whereHas('financialTitle', fn ($query) => $query
                ->where('account_id', $session->account_id)
                ->where('status', '!=', TitleStatus::Cancelled->value))
            ->where('status', '!=', TitleStatus::Cancelled->value)
            ->orderBy('due_date')->orderBy('id')->limit($limit)->get();

        $usedTitles = $this->allocations->confirmedTitleCents($titles->pluck('id'));
        $usedTransactions = $this->allocations->confirmedTransactionCents($transactions->pluck('id'));
        $titleAvailable = $titles->mapWithKeys(fn (TitleInstallment $item): array => [$item->id => max(0, Money::toCents($item->amount) - ($usedTitles[$item->id] ?? 0))])->all();
        $transactionAvailable = $transactions->mapWithKeys(fn (BankTransaction $item): array => [$item->id => max(0, Money::toCents($item->amount) - ($usedTransactions[$item->id] ?? 0))])->all();
        $titles = $titles->filter(fn (TitleInstallment $item): bool => $titleAvailable[$item->id] > 0)->values();
        $transactions = $transactions->filter(fn (BankTransaction $item): bool => $transactionAvailable[$item->id] > 0)->values();

        $drafts = $this->oneToOne($titles, $transactions, $titleAvailable, $transactionAvailable, $usedTitles, $usedTransactions);
        $drafts = array_merge($drafts, $this->oneToMany($titles, $transactions, $titleAvailable, $transactionAvailable));
        $drafts = array_merge($drafts, $this->manyToOne($titles, $transactions, $titleAvailable, $transactionAvailable));
        $drafts = $this->boundedUniqueDrafts($drafts);

        return DB::transaction(function () use ($session, $actorId, $correlationId, $drafts, $titles, $transactions, $titleAvailable, $transactionAvailable): array {
            $version = (string) config('reconciliation_matching.engine_version', 'rules-v1');
            $activeSignatures = [];
            foreach ($drafts as $draft) {
                $signature = $this->signature($draft, $version);
                $activeSignatures[] = $signature;
                $candidate = ReconciliationCandidate::query()->firstOrNew([
                    'reconciliation_session_id' => $session->id,
                    'engine_version' => $version,
                    'signature_hash' => $signature,
                ]);
                if ($candidate->exists && $candidate->status !== ReconciliationCandidateStatus::Pending) {
                    continue;
                }
                $candidate->fill([
                    'type' => $draft['type'], 'status' => ReconciliationCandidateStatus::Pending,
                    'score' => $draft['score'], 'confidence' => $draft['confidence'],
                    'evidence' => $draft['evidence'], 'generated_by' => $actorId,
                    'generated_at' => now(), 'correlation_id' => $correlationId,
                ])->save();
                $candidate->titleAllocations()->delete();
                $candidate->transactionAllocations()->delete();
                foreach ($draft['titles'] as $item) {
                    ReconciliationCandidateTitle::query()->create([
                        'reconciliation_candidate_id' => $candidate->id,
                        'financial_title_id' => $item['title']->financial_title_id,
                        'title_installment_id' => $item['title']->id,
                        'proposed_amount' => Money::fromCents($item['cents']),
                    ]);
                }
                foreach ($draft['transactions'] as $item) {
                    ReconciliationCandidateTransaction::query()->create([
                        'reconciliation_candidate_id' => $candidate->id,
                        'bank_transaction_id' => $item['transaction']->id,
                        'proposed_amount' => Money::fromCents($item['cents']),
                    ]);
                }
            }

            $staleQuery = ReconciliationCandidate::query()
                ->where('reconciliation_session_id', $session->id)->where('engine_version', $version)
                ->where('status', ReconciliationCandidateStatus::Pending->value);
            if ($activeSignatures !== []) {
                $staleQuery->whereNotIn('signature_hash', $activeSignatures);
            }
            $stale = $staleQuery->update(['status' => ReconciliationCandidateStatus::Stale->value, 'correlation_id' => $correlationId, 'updated_at' => now()]);

            $exceptions = $this->persistExceptions($session, $actorId, $correlationId, $version, $drafts, $titles, $transactions, $titleAvailable, $transactionAvailable);
            $this->audit->record('RECONCILIATION_MATCHING_GENERATED', ReconciliationSession::class, $session->id, null, [
                'engine_version' => $version, 'candidate_count' => count($drafts), 'exception_count' => $exceptions, 'stale_count' => $stale,
            ], null, $actorId, $correlationId);
            Log::info('reconciliation_matching_operation', ['correlation_id' => $correlationId, 'user_id' => $actorId, 'session_id' => $session->id, 'action' => 'GENERATED', 'candidate_count' => count($drafts), 'exception_count' => $exceptions]);

            return ['candidates' => count($drafts), 'exceptions' => $exceptions, 'stale' => $stale, 'engine_version' => $version];
        }, 3);
    }

    /** @return list<array<string, mixed>> */
    private function oneToOne(Collection $titles, Collection $transactions, array $ta, array $ba, array $usedTitles, array $usedTransactions): array
    {
        $drafts = [];
        foreach ($transactions as $transaction) {
            foreach ($titles as $title) {
                if (! $this->compatible($title, $transaction)) {
                    continue;
                }
                $exact = $ta[$title->id] === $ba[$transaction->id];
                $partial = ! $exact && (($usedTitles[$title->id] ?? 0) > 0 || ($usedTransactions[$transaction->id] ?? 0) > 0);
                $score = $this->scorer->score($title->financialTitle, $title, $transaction, $exact);
                if (! $exact && (! $partial || $score['score'] < 20)) {
                    continue;
                }
                if ($score['score'] < (int) config('reconciliation_matching.minimum_display_score')) {
                    continue;
                }
                $cents = $exact ? $ta[$title->id] : min($ta[$title->id], $ba[$transaction->id]);
                $score['evidence']['partial_remainder'] = ! $exact;
                $drafts[] = $this->draft(ReconciliationCandidateType::OneToOne, [[$title, $cents]], [[$transaction, $cents]], $score);
            }
        }

        return $drafts;
    }

    /** @return list<array<string, mixed>> */
    private function oneToMany(Collection $titles, Collection $transactions, array $ta, array $ba): array
    {
        $drafts = [];
        foreach ($titles as $title) {
            $pool = $transactions->filter(fn ($transaction): bool => $this->compatible($title, $transaction))->take((int) config('reconciliation_matching.max_composition_pool', 12))->values()->all();
            foreach ($this->combinations($pool) as $group) {
                if (array_sum(array_map(fn ($tx): int => $ba[$tx->id], $group)) !== $ta[$title->id]) {
                    continue;
                }
                $score = $this->bestScore($title, $group);
                if ($score['score'] < (int) config('reconciliation_matching.minimum_display_score')) {
                    continue;
                }
                $drafts[] = $this->draft(ReconciliationCandidateType::OneToMany, [[$title, $ta[$title->id]]], array_map(fn ($tx): array => [$tx, $ba[$tx->id]], $group), $score);
            }
        }

        return $drafts;
    }

    /** @return list<array<string, mixed>> */
    private function manyToOne(Collection $titles, Collection $transactions, array $ta, array $ba): array
    {
        $drafts = [];
        foreach ($transactions as $transaction) {
            $pool = $titles->filter(fn ($title): bool => $this->compatible($title, $transaction))->take((int) config('reconciliation_matching.max_composition_pool', 12))->values()->all();
            foreach ($this->combinations($pool) as $group) {
                if (array_sum(array_map(fn ($title): int => $ta[$title->id], $group)) !== $ba[$transaction->id]) {
                    continue;
                }
                $scores = array_map(fn ($title): array => $this->scorer->score($title->financialTitle, $title, $transaction, true), $group);
                usort($scores, fn ($a, $b): int => $b['score'] <=> $a['score']);
                $score = $scores[0];
                $score['evidence']['composition_size'] = count($group);
                if ($score['score'] < (int) config('reconciliation_matching.minimum_display_score')) {
                    continue;
                }
                $drafts[] = $this->draft(ReconciliationCandidateType::ManyToOne, array_map(fn ($title): array => [$title, $ta[$title->id]], $group), [[$transaction, $ba[$transaction->id]]], $score);
            }
        }

        return $drafts;
    }

    private function compatible(TitleInstallment $title, BankTransaction $transaction): bool
    {
        $financial = $title->financialTitle;
        $expected = $financial->type === FinancialTitleType::Payable ? BankTransactionDirection::Debit : BankTransactionDirection::Credit;

        return $transaction->direction === $expected
            && $financial->currency === $transaction->currency
            && abs($title->due_date->diffInDays($transaction->transaction_date)) <= (int) config('reconciliation_matching.date_window_days');
    }

    /** @return list<array<int, mixed>> */
    private function combinations(array $items): array
    {
        $result = [];
        $count = count($items);
        $max = min((int) config('reconciliation_matching.max_composition_size', 3), $count);
        for ($size = 2; $size <= $max && count($result) < (int) config('reconciliation_matching.max_combinations_per_resource', 100); $size++) {
            $this->combine($items, $size, 0, [], $result);
        }

        return $result;
    }

    private function combine(array $items, int $size, int $offset, array $chosen, array &$result): void
    {
        if (count($chosen) === $size) {
            $result[] = $chosen;

            return;
        }
        for ($i = $offset, $n = count($items); $i < $n && count($result) < (int) config('reconciliation_matching.max_combinations_per_resource', 100); $i++) {
            $this->combine($items, $size, $i + 1, [...$chosen, $items[$i]], $result);
        }
    }

    private function bestScore(TitleInstallment $title, array $transactions): array
    {
        $scores = array_map(fn ($tx): array => $this->scorer->score($title->financialTitle, $title, $tx, true), $transactions);
        usort($scores, fn ($a, $b): int => $b['score'] <=> $a['score']);
        $score = $scores[0];
        $score['evidence']['composition_size'] = count($transactions);

        return $score;
    }

    private function draft(ReconciliationCandidateType $type, array $titles, array $transactions, array $score): array
    {
        return ['type' => $type, 'titles' => array_map(fn ($i): array => ['title' => $i[0], 'cents' => $i[1]], $titles), 'transactions' => array_map(fn ($i): array => ['transaction' => $i[0], 'cents' => $i[1]], $transactions), ...$score];
    }

    private function boundedUniqueDrafts(array $drafts): array
    {
        usort($drafts, fn ($a, $b): int => $b['score'] <=> $a['score']);
        $result = [];
        $seen = [];
        $counts = [];
        $max = (int) config('reconciliation_matching.max_candidates_per_resource', 8);
        foreach ($drafts as $draft) {
            $key = $this->signature($draft, 'draft');
            if (isset($seen[$key])) {
                continue;
            }
            $resources = [...array_map(fn ($i): string => 't'.$i['title']->id, $draft['titles']), ...array_map(fn ($i): string => 'b'.$i['transaction']->id, $draft['transactions'])];
            if (collect($resources)->contains(fn ($id): bool => ($counts[$id] ?? 0) >= $max)) {
                continue;
            }
            $seen[$key] = true;
            foreach ($resources as $id) {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            } $result[] = $draft;
        }

        return $result;
    }

    private function signature(array $draft, string $version): string
    {
        $titles = array_map(fn ($i): string => $i['title']->id.':'.$i['cents'], $draft['titles']);
        sort($titles);
        $transactions = array_map(fn ($i): string => $i['transaction']->id.':'.$i['cents'], $draft['transactions']);
        sort($transactions);

        return hash('sha256', $version.'|'.$draft['type']->value.'|'.implode(',', $titles).'|'.implode(',', $transactions));
    }

    private function persistExceptions(ReconciliationSession $session, int $actorId, string $correlationId, string $version, array $drafts, Collection $titles, Collection $transactions, array $ta, array $ba): int
    {
        $records = [];
        $txCandidateScores = [];
        foreach ($drafts as $draft) {
            foreach ($draft['transactions'] as $item) {
                $txCandidateScores[$item['transaction']->id][] = $draft['score'];
            }
        }
        foreach ($transactions as $transaction) {
            $scores = $txCandidateScores[$transaction->id] ?? [];
            rsort($scores, SORT_NUMERIC);
            $count = count($scores);
            if ($count > 1 && ($scores[0] - $scores[1]) <= (int) config('reconciliation_matching.ambiguity_delta', 5)) {
                $records[] = ['type' => ReconciliationExceptionType::AmbiguousCandidates, 'transaction' => $transaction, 'title' => null, 'difference' => null, 'facts' => ['candidate_count' => $count, 'top_score_gap' => $scores[0] - $scores[1]]];
            } elseif ($count === 0) {
                $strong = $titles->first(fn ($title): bool => $this->compatible($title, $transaction) && $this->scorer->hasStrongIdentifier($title->financialTitle, $transaction));
                if ($strong) {
                    $records[] = ['type' => ReconciliationExceptionType::AmountMismatch, 'transaction' => $transaction, 'title' => $strong, 'difference' => abs($ta[$strong->id] - $ba[$transaction->id]), 'facts' => ['strong_identifier' => true]];
                } elseif (Money::toCents($transaction->amount) > $ba[$transaction->id]) {
                    $records[] = ['type' => ReconciliationExceptionType::PartiallyReconciledRemainder, 'transaction' => $transaction, 'title' => null, 'difference' => null, 'facts' => ['available_remainder' => true]];
                } else {
                    $strongConflict = $titles->first(fn ($title): bool => abs($title->due_date->diffInDays($transaction->transaction_date)) <= (int) config('reconciliation_matching.date_window_days') && $this->scorer->hasStrongIdentifier($title->financialTitle, $transaction));
                    if ($strongConflict) {
                        $records[] = ['type' => ReconciliationExceptionType::StrongIdentifierConflict, 'transaction' => $transaction, 'title' => $strongConflict, 'difference' => abs($ta[$strongConflict->id] - $ba[$transaction->id]), 'facts' => ['direction_or_currency_conflict' => true]];
                    } else {
                        $missing = blank($transaction->document_number) && blank($transaction->bank_reference) && blank($transaction->counterparty_name) && blank($transaction->description_original);
                        $records[] = ['type' => $missing ? ReconciliationExceptionType::MissingRequiredData : ReconciliationExceptionType::NoCandidate, 'transaction' => $transaction, 'title' => null, 'difference' => null, 'facts' => ['candidate_count' => 0]];
                    }
                }
            }
        }
        $titleCandidateIds = collect($drafts)->flatMap(fn (array $draft): array => array_map(fn (array $item): int => $item['title']->id, $draft['titles']))->unique();
        foreach ($titles as $title) {
            if (! $titleCandidateIds->contains($title->id) && Money::toCents($title->amount) > $ta[$title->id]) {
                $records[] = ['type' => ReconciliationExceptionType::PartiallyReconciledRemainder, 'transaction' => null, 'title' => $title, 'difference' => null, 'facts' => ['available_remainder' => true]];
            }
        }
        foreach ($records as $item) {
            $title = $item['title'];
            $transaction = $item['transaction'];
            $signature = hash('sha256', $version.'|'.$item['type']->value.'|'.($title?->id ?? 0).'|'.($transaction?->id ?? 0));
            $exception = ReconciliationException::query()->firstOrNew(['reconciliation_session_id' => $session->id, 'engine_version' => $version, 'signature_hash' => $signature]);
            if ($exception->exists && in_array($exception->status, [ReconciliationExceptionStatus::Resolved, ReconciliationExceptionStatus::Justified], true)) {
                continue;
            }
            $exception->fill([
                'financial_title_id' => $title?->financial_title_id, 'title_installment_id' => $title?->id,
                'bank_transaction_id' => $transaction?->id, 'type' => $item['type'], 'status' => ReconciliationExceptionStatus::Open,
                'amount' => Money::fromCents($transaction ? $ba[$transaction->id] : $ta[$title->id]), 'difference_amount' => $item['difference'] === null ? null : Money::fromCents($item['difference']),
                'evidence' => ['facts' => $item['facts'], 'explanation' => 'Divergência determinística; nenhuma decisão financeira foi executada.'],
                'generated_by' => $actorId, 'generated_at' => now(), 'correlation_id' => $correlationId,
            ])->save();
        }

        return count($records);
    }
}
