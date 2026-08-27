<?php

namespace App\Application\Reconciliation;

use App\Contracts\AuditEventRecorder;
use App\Domain\Banking\Enums\BankTransactionDirection;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use App\Domain\Reconciliation\Enums\ReconciliationMethod;
use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationMatchTitle;
use App\Models\ReconciliationMatchTransaction;
use App\Models\ReconciliationSession;
use App\Models\TitleInstallment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ManualReconciliationService
{
    use AssertsReconciliationSessionOpenForWrite;

    public function __construct(
        private readonly ReconciliationFeature $feature,
        private readonly ReconciliationAllocationQuery $allocations,
        private readonly AuditEventRecorder $audit,
    ) {}

    /**
     * @param  list<ReconciliationTitleAllocationData>  $titleAllocations
     * @param  list<ReconciliationTransactionAllocationData>  $transactionAllocations
     */
    public function confirm(
        int $sessionId,
        array $titleAllocations,
        array $transactionAllocations,
        int $actorId,
        string $correlationId,
    ): ReconciliationMatch {
        $this->feature->assertEnabled();
        $this->assertActor($actorId);
        $titleDrafts = $this->normalizeTitleDrafts($titleAllocations);
        $transactionDrafts = $this->normalizeTransactionDrafts($transactionAllocations);

        $match = DB::transaction(function () use (
            $sessionId,
            $titleDrafts,
            $transactionDrafts,
            $actorId,
            $correlationId,
        ): ReconciliationMatch {
            $session = ReconciliationSession::query()->lockForUpdate()->find($sessionId);
            if (! $session) {
                $this->violation('RECONCILIATION_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
            }
            $this->assertSessionOpenForWrite($session);

            $transactionIds = array_column($transactionDrafts, 'bank_transaction_id');
            /** @var Collection<int, BankTransaction> $transactions */
            $transactions = BankTransaction::query()
                ->whereIn('id', $transactionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($transactions->count() !== count($transactionIds)) {
                $this->violation('RECONCILIATION_TRANSACTION_NOT_FOUND', 'Uma das transações bancárias não existe.');
            }

            $titleIds = array_values(array_unique(array_column($titleDrafts, 'financial_title_id')));
            /** @var Collection<int, FinancialTitle> $titles */
            $titles = FinancialTitle::query()
                ->whereIn('id', $titleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            if ($titles->count() !== count($titleIds)) {
                $this->violation('RECONCILIATION_TITLE_NOT_FOUND', 'Um dos títulos financeiros não existe.');
            }

            /** @var Collection<int, Collection<int, TitleInstallment>> $installmentsByTitle */
            $installmentsByTitle = TitleInstallment::query()
                ->whereIn('financial_title_id', $titleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->groupBy('financial_title_id');

            $resolvedTitles = $this->resolveAndValidateTitles($session, $titleDrafts, $titles, $installmentsByTitle);
            $this->validateTransactions($session, $transactionDrafts, $transactions);
            $this->validateDirectionsAndCurrency($resolvedTitles, $titles, $transactionDrafts, $transactions);
            $this->validateAvailability($resolvedTitles, $transactionDrafts, $transactions, $installmentsByTitle);

            $titleTotal = array_sum(array_column($resolvedTitles, 'allocated_cents'));
            $transactionTotal = array_sum(array_column($transactionDrafts, 'allocated_cents'));
            if ($titleTotal !== $transactionTotal) {
                $this->violation(
                    'RECONCILIATION_UNBALANCED',
                    'O total alocado nos títulos deve ser exatamente igual ao total alocado no banco.',
                );
            }

            $created = ReconciliationMatch::query()->create([
                'reconciliation_session_id' => $session->id,
                'status' => ReconciliationMatchStatus::Confirmed,
                'method' => ReconciliationMethod::Manual,
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
                'correlation_id' => $correlationId,
            ]);

            foreach ($resolvedTitles as $allocation) {
                ReconciliationMatchTitle::query()->create([
                    'reconciliation_match_id' => $created->id,
                    'financial_title_id' => $allocation['financial_title_id'],
                    'title_installment_id' => $allocation['title_installment_id'],
                    'allocated_amount' => Money::fromCents($allocation['allocated_cents']),
                ]);
            }
            foreach ($transactionDrafts as $allocation) {
                ReconciliationMatchTransaction::query()->create([
                    'reconciliation_match_id' => $created->id,
                    'bank_transaction_id' => $allocation['bank_transaction_id'],
                    'allocated_amount' => Money::fromCents($allocation['allocated_cents']),
                ]);
            }

            $session->update([
                'status' => ReconciliationSessionStatus::InReview,
                'updated_by' => $actorId,
            ]);
            $after = $this->snapshot($created, $resolvedTitles, $transactionDrafts);
            $this->audit->record(
                'RECONCILIATION_MATCH_CONFIRMED',
                ReconciliationMatch::class,
                $created->id,
                null,
                $after,
                null,
                $actorId,
                $correlationId,
            );

            return $created->fresh(['session', 'titleAllocations', 'transactionAllocations']);
        }, 3);

        $this->log($match, $actorId, 'MATCH_CONFIRMED');

        return $match;
    }

    public function void(
        int $sessionId,
        int $matchId,
        string $reason,
        int $actorId,
        string $correlationId,
    ): ReconciliationMatch {
        $this->feature->assertEnabled();
        $this->assertActor($actorId);
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            $this->violation('RECONCILIATION_VOID_REASON_REQUIRED', 'Informe um motivo de 1 a 1000 caracteres para desfazer.');
        }

        $match = DB::transaction(function () use ($sessionId, $matchId, $reason, $actorId, $correlationId): ReconciliationMatch {
            $session = ReconciliationSession::query()->lockForUpdate()->find($sessionId);
            if (! $session) {
                $this->violation('RECONCILIATION_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
            }
            $this->assertSessionOpenForWrite($session);

            $record = ReconciliationMatch::query()
                ->where('reconciliation_session_id', $session->id)
                ->lockForUpdate()
                ->find($matchId);
            if (! $record) {
                $this->violation('RECONCILIATION_MATCH_NOT_FOUND', 'O match não pertence à sessão informada.');
            }
            if ($record->status === ReconciliationMatchStatus::Voided) {
                $this->violation('RECONCILIATION_MATCH_ALREADY_VOIDED', 'O match já foi desfeito.');
            }

            $titleAllocations = $record->titleAllocations()->orderBy('id')->get();
            $transactionAllocations = $record->transactionAllocations()->orderBy('id')->get();
            BankTransaction::query()
                ->whereIn('id', $transactionAllocations->pluck('bank_transaction_id'))
                ->orderBy('id')->lockForUpdate()->get();
            FinancialTitle::query()
                ->whereIn('id', $titleAllocations->pluck('financial_title_id'))
                ->orderBy('id')->lockForUpdate()->get();
            TitleInstallment::query()
                ->whereIn('id', $titleAllocations->pluck('title_installment_id')->filter())
                ->orderBy('id')->lockForUpdate()->get();

            $before = [
                'id' => $record->id,
                'session_id' => $session->id,
                'status' => $record->status->value,
                'method' => $record->method->value,
                'confirmed_by' => $record->confirmed_by,
                'confirmed_at' => $record->confirmed_at->toIso8601String(),
            ];
            $record->update([
                'status' => ReconciliationMatchStatus::Voided,
                'voided_by' => $actorId,
                'voided_at' => now(),
                'void_reason' => $reason,
                'correlation_id' => $correlationId,
            ]);
            $session->update(['updated_by' => $actorId]);
            $after = array_replace($before, [
                'status' => ReconciliationMatchStatus::Voided->value,
                'voided_by' => $actorId,
                'voided_at' => $record->voided_at->toIso8601String(),
                'void_reason' => $reason,
            ]);
            $this->audit->record(
                'RECONCILIATION_MATCH_VOIDED',
                ReconciliationMatch::class,
                $record->id,
                $before,
                $after,
                null,
                $actorId,
                $correlationId,
            );

            return $record->fresh(['session', 'titleAllocations', 'transactionAllocations']);
        }, 3);

        $this->log($match, $actorId, 'MATCH_VOIDED');

        return $match;
    }

    /**
     * @param  list<ReconciliationTitleAllocationData>  $allocations
     * @return list<array{financial_title_id: int, title_installment_id: ?int, allocated_cents: int}>
     */
    private function normalizeTitleDrafts(array $allocations): array
    {
        if ($allocations === []) {
            $this->violation('RECONCILIATION_TITLES_REQUIRED', 'Selecione ao menos um título ou parcela.');
        }

        $result = [];
        $seen = [];
        foreach ($allocations as $allocation) {
            if (! $allocation instanceof ReconciliationTitleAllocationData) {
                $this->violation('RECONCILIATION_INVALID_ALLOCATION', 'A alocação de título é inválida.');
            }
            $titleId = (int) ($allocation->financialTitleId ?? 0);
            $installmentId = (int) ($allocation->titleInstallmentId ?? 0);
            if ($titleId < 1 && $installmentId < 1) {
                $this->violation('RECONCILIATION_TITLE_NOT_FOUND', 'Informe o título ou a parcela da alocação.');
            }
            if ($titleId < 1) {
                $titleId = (int) (TitleInstallment::query()->whereKey($installmentId)->value('financial_title_id') ?? 0);
                if ($titleId < 1) {
                    $this->violation('RECONCILIATION_TITLE_NOT_FOUND', 'A parcela informada não existe.');
                }
            }

            $key = $installmentId > 0 ? 'installment:'.$installmentId : 'title:'.$titleId;
            if (isset($seen[$key])) {
                $this->violation('RECONCILIATION_DUPLICATE_ALLOCATION', 'O mesmo título ou parcela foi informado mais de uma vez.');
            }
            $seen[$key] = true;
            $result[] = [
                'financial_title_id' => $titleId,
                'title_installment_id' => $installmentId > 0 ? $installmentId : null,
                'allocated_cents' => $this->positiveCents($allocation->amount),
            ];
        }

        return $result;
    }

    /**
     * @param  list<ReconciliationTransactionAllocationData>  $allocations
     * @return list<array{bank_transaction_id: int, allocated_cents: int}>
     */
    private function normalizeTransactionDrafts(array $allocations): array
    {
        if ($allocations === []) {
            $this->violation('RECONCILIATION_TRANSACTIONS_REQUIRED', 'Selecione ao menos uma transação bancária.');
        }

        $result = [];
        $seen = [];
        foreach ($allocations as $allocation) {
            if (! $allocation instanceof ReconciliationTransactionAllocationData || $allocation->bankTransactionId < 1) {
                $this->violation('RECONCILIATION_INVALID_ALLOCATION', 'A alocação bancária é inválida.');
            }
            if (isset($seen[$allocation->bankTransactionId])) {
                $this->violation('RECONCILIATION_DUPLICATE_ALLOCATION', 'A mesma transação foi informada mais de uma vez.');
            }
            $seen[$allocation->bankTransactionId] = true;
            $result[] = [
                'bank_transaction_id' => $allocation->bankTransactionId,
                'allocated_cents' => $this->positiveCents($allocation->amount),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array{financial_title_id: int, title_installment_id: ?int, allocated_cents: int}>  $drafts
     * @param  Collection<int, FinancialTitle>  $titles
     * @param  Collection<int, Collection<int, TitleInstallment>>  $installmentsByTitle
     * @return list<array{financial_title_id: int, title_installment_id: int, allocated_cents: int}>
     */
    private function resolveAndValidateTitles(
        ReconciliationSession $session,
        array $drafts,
        Collection $titles,
        Collection $installmentsByTitle,
    ): array {
        $resolved = [];
        $seenInstallments = [];
        foreach ($drafts as $draft) {
            $title = $titles->get($draft['financial_title_id']);
            if (! $title) {
                $this->violation('RECONCILIATION_TITLE_NOT_FOUND', 'Um dos títulos financeiros não existe.');
            }
            if ($title->status === TitleStatus::Cancelled) {
                $this->violation('RECONCILIATION_TITLE_CANCELLED', 'Título cancelado não pode ser conciliado.');
            }
            if ($title->account_id === null) {
                $this->violation('RECONCILIATION_TITLE_ACCOUNT_REQUIRED', 'O título precisa possuir uma conta explícita antes da conciliação.');
            }
            if ((int) $title->account_id !== (int) $session->account_id) {
                $this->violation('RECONCILIATION_ACCOUNT_MISMATCH', 'O título não pertence à conta da sessão.');
            }

            /** @var Collection<int, TitleInstallment> $installments */
            $installments = $installmentsByTitle->get($title->id, collect())->values();
            if ($installments->isEmpty()) {
                $this->violation('RECONCILIATION_INSTALLMENT_REQUIRED', 'O título não possui parcela conciliável.');
            }
            $installment = $draft['title_installment_id']
                ? $installments->firstWhere('id', $draft['title_installment_id'])
                : ($installments->count() === 1 ? $installments->first() : null);
            if (! $installment) {
                $this->violation('RECONCILIATION_INSTALLMENT_REQUIRED', 'Informe explicitamente a parcela de um título parcelado.');
            }
            if ($installment->status === TitleStatus::Cancelled) {
                $this->violation('RECONCILIATION_TITLE_CANCELLED', 'Parcela cancelada não pode ser conciliada.');
            }
            if (isset($seenInstallments[$installment->id])) {
                $this->violation('RECONCILIATION_DUPLICATE_ALLOCATION', 'A mesma parcela foi informada mais de uma vez.');
            }
            $seenInstallments[$installment->id] = true;
            $resolved[] = [
                'financial_title_id' => $title->id,
                'title_installment_id' => $installment->id,
                'allocated_cents' => $draft['allocated_cents'],
            ];
        }

        return $resolved;
    }

    /**
     * @param  list<array{bank_transaction_id: int, allocated_cents: int}>  $drafts
     * @param  Collection<int, BankTransaction>  $transactions
     */
    private function validateTransactions(ReconciliationSession $session, array $drafts, Collection $transactions): void
    {
        foreach ($drafts as $draft) {
            $transaction = $transactions->get($draft['bank_transaction_id']);
            if ((int) $transaction->account_id !== (int) $session->account_id) {
                $this->violation('RECONCILIATION_ACCOUNT_MISMATCH', 'A transação bancária não pertence à conta da sessão.');
            }
            if (! $transaction->transaction_date->betweenIncluded($session->period_start, $session->period_end)) {
                $this->violation('RECONCILIATION_TRANSACTION_OUTSIDE_PERIOD', 'A transação bancária está fora do período da sessão.');
            }
        }
    }

    /**
     * @param  list<array{financial_title_id: int, title_installment_id: int, allocated_cents: int}>  $titleDrafts
     * @param  Collection<int, FinancialTitle>  $titles
     * @param  list<array{bank_transaction_id: int, allocated_cents: int}>  $transactionDrafts
     * @param  Collection<int, BankTransaction>  $transactions
     */
    private function validateDirectionsAndCurrency(
        array $titleDrafts,
        Collection $titles,
        array $transactionDrafts,
        Collection $transactions,
    ): void {
        $types = array_values(array_unique(array_map(
            fn (array $allocation): string => $titles->get($allocation['financial_title_id'])->type->value,
            $titleDrafts,
        )));
        $directions = array_values(array_unique(array_map(
            fn (array $allocation): string => $transactions->get($allocation['bank_transaction_id'])->direction->value,
            $transactionDrafts,
        )));
        if (count($types) !== 1 || count($directions) !== 1) {
            $this->violation('RECONCILIATION_MIXED_DIRECTIONS', 'Não misture títulos ou transações de direções diferentes no mesmo match.');
        }

        $expected = $types[0] === FinancialTitleType::Payable->value
            ? BankTransactionDirection::Debit->value
            : BankTransactionDirection::Credit->value;
        if ($directions[0] !== $expected) {
            $this->violation('RECONCILIATION_DIRECTION_MISMATCH', 'A direção bancária é incompatível com o tipo do título.');
        }

        $currencies = [];
        foreach ($titleDrafts as $allocation) {
            $currencies[] = $titles->get($allocation['financial_title_id'])->currency;
        }
        foreach ($transactionDrafts as $allocation) {
            $currencies[] = $transactions->get($allocation['bank_transaction_id'])->currency;
        }
        if (count(array_unique($currencies)) !== 1) {
            $this->violation('RECONCILIATION_CURRENCY_MISMATCH', 'Todos os recursos do match devem usar a mesma moeda.');
        }
    }

    /**
     * @param  list<array{financial_title_id: int, title_installment_id: int, allocated_cents: int}>  $titleDrafts
     * @param  list<array{bank_transaction_id: int, allocated_cents: int}>  $transactionDrafts
     * @param  Collection<int, BankTransaction>  $transactions
     * @param  Collection<int, Collection<int, TitleInstallment>>  $installmentsByTitle
     */
    private function validateAvailability(
        array $titleDrafts,
        array $transactionDrafts,
        Collection $transactions,
        Collection $installmentsByTitle,
    ): void {
        $installmentIds = array_column($titleDrafts, 'title_installment_id');
        $usedTitles = $this->allocations->confirmedTitleCents($installmentIds);
        foreach ($titleDrafts as $draft) {
            $installment = $installmentsByTitle
                ->get($draft['financial_title_id'], collect())
                ->firstWhere('id', $draft['title_installment_id']);
            $available = Money::toCents($installment->amount) - ($usedTitles[$installment->id] ?? 0);
            if ($draft['allocated_cents'] > $available) {
                $this->violation('RECONCILIATION_TITLE_OVER_ALLOCATED', 'A alocação excede o valor disponível do título/parcela.');
            }
        }

        $transactionIds = array_column($transactionDrafts, 'bank_transaction_id');
        $usedTransactions = $this->allocations->confirmedTransactionCents($transactionIds);
        foreach ($transactionDrafts as $draft) {
            $transaction = $transactions->get($draft['bank_transaction_id']);
            $available = Money::toCents($transaction->amount) - ($usedTransactions[$transaction->id] ?? 0);
            if ($draft['allocated_cents'] > $available) {
                $this->violation('RECONCILIATION_TRANSACTION_OVER_ALLOCATED', 'A alocação excede o valor disponível da transação bancária.');
            }
        }
    }

    private function positiveCents(string $amount): int
    {
        try {
            $cents = Money::toCents($amount);
        } catch (Throwable) {
            $cents = 0;
        }
        if ($cents <= 0) {
            $this->violation('RECONCILIATION_INVALID_AMOUNT', 'Toda alocação deve possuir valor positivo com até duas casas decimais.');
        }

        return $cents;
    }

    private function assertActor(int $actorId): void
    {
        if ($actorId < 1) {
            $this->violation('RECONCILIATION_ACTOR_REQUIRED', 'O usuário responsável é obrigatório.');
        }
    }

    /**
     * @param  list<array{financial_title_id: int, title_installment_id: int, allocated_cents: int}>  $titles
     * @param  list<array{bank_transaction_id: int, allocated_cents: int}>  $transactions
     * @return array<string, mixed>
     */
    private function snapshot(ReconciliationMatch $match, array $titles, array $transactions): array
    {
        return [
            'id' => $match->id,
            'session_id' => $match->reconciliation_session_id,
            'status' => $match->status->value,
            'method' => $match->method->value,
            'confirmed_by' => $match->confirmed_by,
            'confirmed_at' => $match->confirmed_at->toIso8601String(),
            'titles' => array_map(fn (array $item): array => [
                'financial_title_id' => $item['financial_title_id'],
                'title_installment_id' => $item['title_installment_id'],
                'allocated_amount' => Money::fromCents($item['allocated_cents']),
            ], $titles),
            'transactions' => array_map(fn (array $item): array => [
                'bank_transaction_id' => $item['bank_transaction_id'],
                'allocated_amount' => Money::fromCents($item['allocated_cents']),
            ], $transactions),
        ];
    }

    private function log(ReconciliationMatch $match, int $actorId, string $action): void
    {
        Log::info('reconciliation_v2_operation', [
            'correlation_id' => $match->correlation_id,
            'user_id' => $actorId,
            'session_id' => $match->reconciliation_session_id,
            'match_id' => $match->id,
            'account_id' => $match->session->account_id,
            'action' => $action,
            'status' => $match->status->value,
        ]);
    }

    private function violation(string $rule, string $message): never
    {
        throw new ReconciliationRuleViolation($rule, $message);
    }
}
