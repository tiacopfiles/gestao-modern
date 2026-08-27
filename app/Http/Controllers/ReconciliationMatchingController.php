<?php

namespace App\Http\Controllers;

use App\Application\Reconciliation\ReconciliationCandidateService;
use App\Application\Reconciliation\ReconciliationExceptionService;
use App\Application\Reconciliation\ReconciliationMatchingEngine;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Http\Requests\JustifyReconciliationExceptionRequest;
use App\Http\Requests\RejectReconciliationCandidateRequest;
use App\Models\ReconciliationCandidate;
use App\Models\ReconciliationException;
use App\Models\ReconciliationSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReconciliationMatchingController extends Controller
{
    public function __construct(private readonly ReconciliationMatchingEngine $engine, private readonly ReconciliationCandidateService $candidates, private readonly ReconciliationExceptionService $exceptions) {}

    public function generate(Request $request, ReconciliationSession $session): RedirectResponse
    {
        try {
            $result = $this->engine->generate($session->id, (int) $request->user()->getAuthIdentifier(), (string) Str::uuid());
        } catch (ReconciliationRuleViolation $e) {
            return back()->withErrors([$e->rule => $e->getMessage()]);
        }

        return back()->with('success', "Geração {$result['engine_version']}: {$result['candidates']} candidato(s), {$result['exceptions']} divergência(s), {$result['stale']} obsoleto(s). Nenhuma conciliação foi confirmada.");
    }

    public function showCandidate(ReconciliationSession $session, int $candidate): View
    {
        $record = ReconciliationCandidate::query()->where('reconciliation_session_id', $session->id)->with(['titleAllocations.financialTitle', 'titleAllocations.titleInstallment', 'transactionAllocations.bankTransaction', 'match'])->findOrFail($candidate);

        return view('reconciliation-v2.candidate', compact('session', 'record'));
    }

    public function accept(Request $request, ReconciliationSession $session, int $candidate): RedirectResponse
    {
        try {
            $match = $this->candidates->accept($session->id, $candidate, (int) $request->user()->getAuthIdentifier(), (string) Str::uuid());
        } catch (ReconciliationRuleViolation $e) {
            return back()->withErrors([$e->rule => $e->getMessage()]);
        }

        return redirect()->route('reconciliation-v2.matches.show', [$session, $match])->with('success', 'Candidato aceito explicitamente e confirmado pelo serviço manual após revalidação.');
    }

    public function reject(RejectReconciliationCandidateRequest $request, ReconciliationSession $session, int $candidate): RedirectResponse
    {
        try {
            $this->candidates->reject($session->id, $candidate, (string) $request->validated('reason'), (int) $request->user()->getAuthIdentifier(), (string) Str::uuid());
        } catch (ReconciliationRuleViolation $e) {
            return back()->withErrors([$e->rule => $e->getMessage()]);
        }

        return back()->with('success', 'Candidato rejeitado; histórico e evidências foram preservados.');
    }

    public function showException(ReconciliationSession $session, int $exception): View
    {
        $record = ReconciliationException::query()->where('reconciliation_session_id', $session->id)->with(['financialTitle', 'titleInstallment', 'bankTransaction'])->findOrFail($exception);

        return view('reconciliation-v2.exception', compact('session', 'record'));
    }

    public function justify(JustifyReconciliationExceptionRequest $request, ReconciliationSession $session, int $exception): RedirectResponse
    {
        try {
            $this->exceptions->justify($session->id, $exception, (string) $request->validated('reason'), (int) $request->user()->getAuthIdentifier(), (string) Str::uuid());
        } catch (ReconciliationRuleViolation $e) {
            return back()->withErrors([$e->rule => $e->getMessage()]);
        }

        return back()->with('success', 'Divergência justificada sem qualquer efeito financeiro.');
    }
}
