<?php

namespace App\Http\Controllers;

use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationAllocationQuery;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Http\Requests\StoreManualReconciliationRequest;
use App\Http\Requests\StoreReconciliationSessionRequest;
use App\Http\Requests\VoidReconciliationMatchRequest;
use App\Models\BankTransaction;
use App\Models\Conta;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationSession;
use App\Models\TitleInstallment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReconciliationV2Controller extends Controller
{
    public function __construct(
        private readonly ReconciliationSessionService $sessions,
        private readonly ManualReconciliationService $matches,
        private readonly ReconciliationAllocationQuery $allocations,
    ) {}

    public function index(Request $request): View
    {
        $query = ReconciliationSession::query()->with('creator');
        if ($request->filled('account_id')) {
            $query->where('account_id', (int) $request->input('account_id'));
        }
        if (in_array($request->input('status'), ['OPEN', 'IN_REVIEW'], true)) {
            $query->where('status', $request->input('status'));
        }
        $records = $query->latest('id')->paginate(20)->withQueryString();
        $accounts = Conta::query()->orderBy('nome')->get();
        $accountNames = Conta::query()
            ->whereIn('id', $records->getCollection()->pluck('account_id')->unique())
            ->pluck('nome', 'id');

        return view('reconciliation-v2.index', compact('records', 'accounts', 'accountNames'));
    }

    public function create(): View
    {
        $accounts = Conta::query()->orderBy('nome')->get();

        return view('reconciliation-v2.create', compact('accounts'));
    }

    public function store(StoreReconciliationSessionRequest $request): RedirectResponse
    {
        try {
            $session = $this->sessions->create(
                (int) $request->validated('account_id'),
                (string) $request->validated('period_start'),
                (string) $request->validated('period_end'),
                (int) $request->user()->getAuthIdentifier(),
                (string) Str::uuid(),
            );
        } catch (ReconciliationRuleViolation $exception) {
            return back()->withErrors([$exception->rule => $exception->getMessage()])->withInput();
        }

        return redirect()->route('reconciliation-v2.show', $session)
            ->with('success', 'Sessão da nova conciliação criada.');
    }

    public function show(Request $request, ReconciliationSession $session): View
    {
        $account = Conta::query()->find($session->account_id);
        $titleQuery = TitleInstallment::query()
            ->with(['financialTitle.settlements'])
            ->whereHas('financialTitle', function ($query) use ($request, $session): void {
                $query->where('account_id', $session->account_id)
                    ->where('status', '!=', TitleStatus::Cancelled->value);
                if (in_array($request->input('title_type'), ['PAYABLE', 'RECEIVABLE'], true)) {
                    $query->where('type', $request->input('title_type'));
                }
                if ($request->filled('document')) {
                    $query->where('document_number', 'like', '%'.$request->string('document')->trim().'%');
                }
                if ($request->filled('party')) {
                    $query->where('party_name', 'like', '%'.$request->string('party')->trim().'%');
                }
                if (in_array($request->input('title_status'), ['OPEN', 'PARTIALLY_SETTLED', 'SETTLED'], true)) {
                    $query->where('status', $request->input('title_status'));
                }
            });
        if ($request->filled('due_from')) {
            $titleQuery->whereDate('due_date', '>=', $request->input('due_from'));
        }
        if ($request->filled('due_to')) {
            $titleQuery->whereDate('due_date', '<=', $request->input('due_to'));
        }
        if ($this->isMoney($request->input('title_amount'))) {
            $titleQuery->where('amount', $request->input('title_amount'));
        }
        $titles = $titleQuery->orderBy('due_date')->orderBy('id')
            ->paginate(15, ['*'], 'titles_page')->withQueryString();
        $usedTitles = $this->allocations->confirmedTitleCents($titles->getCollection()->pluck('id'));
        $titleAvailability = [];
        foreach ($titles as $installment) {
            $total = Money::toCents($installment->amount);
            $used = $usedTitles[$installment->id] ?? 0;
            $titleAvailability[$installment->id] = $this->availability($total, $used);
        }

        $transactionQuery = BankTransaction::query()
            ->where('account_id', $session->account_id)
            ->whereBetween('transaction_date', [$session->period_start, $session->period_end]);
        if (in_array($request->input('direction'), ['CREDIT', 'DEBIT'], true)) {
            $transactionQuery->where('direction', $request->input('direction'));
        }
        if ($request->filled('bank_description')) {
            $transactionQuery->where('description_original', 'like', '%'.$request->string('bank_description')->trim().'%');
        }
        if ($request->filled('bank_from')) {
            $transactionQuery->whereDate('transaction_date', '>=', $request->input('bank_from'));
        }
        if ($request->filled('bank_to')) {
            $transactionQuery->whereDate('transaction_date', '<=', $request->input('bank_to'));
        }
        if ($this->isMoney($request->input('bank_amount'))) {
            $transactionQuery->where('amount', $request->input('bank_amount'));
        }
        $transactions = $transactionQuery->orderBy('transaction_date')->orderBy('id')
            ->paginate(15, ['*'], 'transactions_page')->withQueryString();
        $usedTransactions = $this->allocations->confirmedTransactionCents($transactions->getCollection()->pluck('id'));
        $transactionAvailability = [];
        foreach ($transactions as $transaction) {
            $total = Money::toCents($transaction->amount);
            $used = $usedTransactions[$transaction->id] ?? 0;
            $transactionAvailability[$transaction->id] = $this->availability($total, $used);
        }

        $history = $session->matches()
            ->with([
                'confirmer', 'voider',
                'titleAllocations.financialTitle', 'titleAllocations.titleInstallment',
                'transactionAllocations.bankTransaction',
            ])
            ->latest('id')->paginate(10, ['*'], 'matches_page')->withQueryString();

        $candidates = null;
        $exceptions = null;
        if (config('reconciliation.matching_enabled', false)) {
            $candidateQuery = $session->candidates()->with(['titleAllocations', 'transactionAllocations']);
            if (in_array($request->input('candidate_status'), ['PENDING', 'ACCEPTED', 'REJECTED', 'STALE'], true)) {
                $candidateQuery->where('status', $request->input('candidate_status'));
            }
            if (in_array($request->input('confidence'), ['HIGH', 'MEDIUM', 'LOW'], true)) {
                $candidateQuery->where('confidence', $request->input('confidence'));
            }
            $candidates = $candidateQuery->orderByDesc('score')->latest('id')->paginate(10, ['*'], 'candidates_page')->withQueryString();
            $exceptionQuery = $session->exceptions();
            if (in_array($request->input('exception_status'), ['OPEN', 'IN_REVIEW', 'RESOLVED', 'JUSTIFIED'], true)) {
                $exceptionQuery->where('status', $request->input('exception_status'));
            }
            if ($request->filled('exception_type')) {
                $exceptionQuery->where('type', $request->input('exception_type'));
            }
            if ($request->filled('resource_id')) {
                $exceptionQuery->where(fn ($q) => $q->where('bank_transaction_id', (int) $request->input('resource_id'))->orWhere('title_installment_id', (int) $request->input('resource_id')));
            }
            $exceptions = $exceptionQuery->latest('id')->paginate(10, ['*'], 'exceptions_page')->withQueryString();
        }

        $latestClosure = null;
        if (config('reconciliation.closing_enabled', false)) {
            $latestClosure = $session->closures()->orderByDesc('sequence_number')->first();
        }

        return view('reconciliation-v2.show', compact(
            'session', 'account', 'titles', 'titleAvailability', 'transactions',
            'transactionAvailability', 'history', 'candidates', 'exceptions', 'latestClosure',
        ));
    }

    public function storeMatch(
        StoreManualReconciliationRequest $request,
        ReconciliationSession $session,
    ): RedirectResponse {
        $data = $request->validated();
        $titleAmounts = $data['title_allocations'];
        $transactionAmounts = $data['transaction_allocations'];
        $titles = array_map(
            fn (int|string $id): ReconciliationTitleAllocationData => new ReconciliationTitleAllocationData(
                null,
                (int) $id,
                (string) $titleAmounts[(string) $id],
            ),
            $data['title_installment_ids'],
        );
        $transactions = array_map(
            fn (int|string $id): ReconciliationTransactionAllocationData => new ReconciliationTransactionAllocationData(
                (int) $id,
                (string) $transactionAmounts[(string) $id],
            ),
            $data['bank_transaction_ids'],
        );

        try {
            $match = $this->matches->confirm(
                $session->id,
                $titles,
                $transactions,
                (int) $request->user()->getAuthIdentifier(),
                (string) Str::uuid(),
            );
        } catch (ReconciliationRuleViolation $exception) {
            return back()->withErrors([$exception->rule => $exception->getMessage()])->withInput();
        }

        return redirect()->route('reconciliation-v2.matches.show', [$session, $match])
            ->with('success', 'Match manual confirmado sem alterar o título ou a transação bancária.');
    }

    public function showMatch(ReconciliationSession $session, int $match): View
    {
        $record = ReconciliationMatch::query()
            ->where('reconciliation_session_id', $session->id)
            ->with([
                'session', 'confirmer', 'voider',
                'titleAllocations.financialTitle', 'titleAllocations.titleInstallment',
                'transactionAllocations.bankTransaction',
            ])
            ->findOrFail($match);
        $account = Conta::query()->find($session->account_id);

        return view('reconciliation-v2.match', compact('session', 'record', 'account'));
    }

    public function voidMatch(
        VoidReconciliationMatchRequest $request,
        ReconciliationSession $session,
        int $match,
    ): RedirectResponse {
        try {
            $record = $this->matches->void(
                $session->id,
                $match,
                (string) $request->validated('reason'),
                (int) $request->user()->getAuthIdentifier(),
                (string) Str::uuid(),
            );
        } catch (ReconciliationRuleViolation $exception) {
            return back()->withErrors([$exception->rule => $exception->getMessage()])->withInput();
        }

        return redirect()->route('reconciliation-v2.matches.show', [$session, $record])
            ->with('success', 'Match desfeito sem excluir o histórico ou alterar fatos financeiros.');
    }

    /** @return array{total: string, allocated: string, available: string, status: string} */
    private function availability(int $total, int $used): array
    {
        $available = max(0, $total - $used);
        $status = match (true) {
            $used <= 0 => 'NOT_RECONCILED',
            $available > 0 => 'PARTIALLY_RECONCILED',
            default => 'RECONCILED',
        };

        return [
            'total' => Money::fromCents($total),
            'allocated' => Money::fromCents($used),
            'available' => Money::fromCents($available),
            'status' => $status,
        ];
    }

    private function isMoney(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:0|[1-9]\d{0,12})\.\d{2}$/', $value) === 1;
    }
}
