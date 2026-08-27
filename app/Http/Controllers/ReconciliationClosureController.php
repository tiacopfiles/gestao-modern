<?php

namespace App\Http\Controllers;

use App\Application\Reconciliation\ReconciliationClosureService;
use App\Application\Reconciliation\ReconciliationClosureSnapshotBuilder;
use App\Application\Reconciliation\ReconciliationClosureValidator;
use App\Application\Reconciliation\ReconciliationReopeningService;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Http\Requests\ReopenReconciliationClosureRequest;
use App\Http\Requests\StoreReconciliationClosureRequest;
use App\Models\Conta;
use App\Models\ReconciliationClosure;
use App\Models\ReconciliationSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReconciliationClosureController extends Controller
{
    public function __construct(
        private readonly ReconciliationClosureService $closures,
        private readonly ReconciliationReopeningService $reopenings,
        private readonly ReconciliationClosureValidator $validator,
        private readonly ReconciliationClosureSnapshotBuilder $snapshotBuilder,
    ) {}

    public function create(ReconciliationSession $session): View
    {
        $account = Conta::query()->find($session->account_id);
        $readiness = $this->validator->readiness($session);
        $preview = $this->snapshotBuilder->build($session);
        $matches = $session->matches()->with(['titleAllocations', 'transactionAllocations'])->orderBy('id')->get();

        return view('reconciliation-v2.closure.create', compact('session', 'account', 'readiness', 'preview', 'matches'));
    }

    public function store(StoreReconciliationClosureRequest $request, ReconciliationSession $session): RedirectResponse
    {
        try {
            $this->closures->close($session->id, (int) $request->user()->getAuthIdentifier(), (string) Str::uuid());
        } catch (ReconciliationRuleViolation $exception) {
            return back()->withErrors([$exception->rule => $exception->getMessage()]);
        }

        return redirect()->route('reconciliation-v2.show', $session)
            ->with('success', 'Fechamento confirmado. O conteúdo fechado agora é reproduzível e auditável.');
    }

    public function history(ReconciliationSession $session): View
    {
        $account = Conta::query()->find($session->account_id);
        $closures = $session->closures()->with('reopenings')->orderByDesc('sequence_number')->paginate(10)->withQueryString();

        return view('reconciliation-v2.closure.history', compact('session', 'account', 'closures'));
    }

    public function show(ReconciliationSession $session, int $closure): View
    {
        $account = Conta::query()->find($session->account_id);
        $record = ReconciliationClosure::query()
            ->where('reconciliation_session_id', $session->id)
            ->with(['matches', 'exceptions', 'metrics', 'reopenings', 'closedByUser', 'previousClosure'])
            ->findOrFail($closure);

        return view('reconciliation-v2.closure.show', compact('session', 'account', 'record'));
    }

    public function reopen(ReopenReconciliationClosureRequest $request, ReconciliationSession $session, int $closure): RedirectResponse
    {
        try {
            $this->reopenings->reopen(
                $session->id,
                $closure,
                (string) $request->validated('reason'),
                (int) $request->user()->getAuthIdentifier(),
                (string) Str::uuid(),
            );
        } catch (ReconciliationRuleViolation $exception) {
            return back()->withErrors([$exception->rule => $exception->getMessage()])->withInput();
        }

        return redirect()->route('reconciliation-v2.closure.show', [$session, $closure])
            ->with('success', 'Fechamento reaberto de forma excepcional e auditada. O fechamento anterior foi preservado.');
    }
}
