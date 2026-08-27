<?php

namespace App\Application\Reconciliation;

use App\Domain\Financial\Money;
use App\Domain\Reconciliation\Closure\Enums\ReconciliationClosureStatus;
use App\Domain\Reconciliation\Closure\ReconciliationClosureReadiness;
use App\Domain\Reconciliation\Enums\ReconciliationCandidateStatus;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionStatus;
use App\Domain\Reconciliation\Enums\ReconciliationMatchStatus;
use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use App\Models\ReconciliationClosure;
use App\Models\ReconciliationSession;

/**
 * Regras de negócio ainda não validadas pelo financeiro (política de divergência
 * extraordinária, candidato pendente bloqueando, sessão vazia bloqueando, tolerância
 * de saldo) permanecem fora daqui como blocker fixo — ver FASE_6_PERGUNTAS_NEGOCIO.md.
 * Esta implementação usa exclusivamente os defaults seguros documentados (política
 * Governada de divergências).
 */
class ReconciliationClosureValidator
{
    public function readiness(ReconciliationSession $session): ReconciliationClosureReadiness
    {
        $blockers = [];
        $warnings = [];

        if ($session->status === ReconciliationSessionStatus::Closed) {
            $blockers[] = ['code' => 'CLOSURE_SESSION_ALREADY_CLOSED', 'message' => 'A sessão já está fechada.'];
        }

        $matches = $session->matches()->with(['titleAllocations', 'transactionAllocations'])->get();
        foreach ($matches as $match) {
            if ($match->status !== ReconciliationMatchStatus::Confirmed) {
                continue;
            }
            $titleCents = (int) $match->titleAllocations->sum(
                fn ($allocation): int => Money::toCents((string) $allocation->allocated_amount),
            );
            $transactionCents = (int) $match->transactionAllocations->sum(
                fn ($allocation): int => Money::toCents((string) $allocation->allocated_amount),
            );
            if ($titleCents !== $transactionCents) {
                $blockers[] = [
                    'code' => 'CLOSURE_INVALID_MATCH_STATE',
                    'message' => "O match #{$match->id} possui alocações de título e banco com somas diferentes.",
                ];
                break;
            }
        }

        $overlap = ReconciliationClosure::query()
            ->where('account_id', $session->account_id)
            ->where('status', ReconciliationClosureStatus::Closed->value)
            ->where('reconciliation_session_id', '!=', $session->id)
            ->where('period_start', '<=', $session->period_end)
            ->where('period_end', '>=', $session->period_start)
            ->exists();
        if ($overlap) {
            $blockers[] = [
                'code' => 'CLOSURE_PERIOD_OVERLAP',
                'message' => 'Já existe um fechamento vigente da mesma conta com período sobreposto.',
            ];
        }

        $openExceptions = $session->exceptions()
            ->whereIn('status', [ReconciliationExceptionStatus::Open->value, ReconciliationExceptionStatus::InReview->value])
            ->count();
        if ($openExceptions > 0) {
            $blockers[] = [
                'code' => 'CLOSURE_OPEN_EXCEPTIONS',
                'message' => "Existem {$openExceptions} divergência(s) aberta(s) ou em revisão que impedem o fechamento (política Governada).",
            ];
        }

        $pendingCandidates = $session->candidates()->where('status', ReconciliationCandidateStatus::Pending->value)->count();
        if ($pendingCandidates > 0) {
            $warnings[] = [
                'code' => 'CLOSURE_PENDING_CANDIDATES',
                'message' => "Existem {$pendingCandidates} candidato(s) pendente(s) não decidido(s).",
            ];
        }

        $confirmedCount = $matches->filter(fn ($match): bool => $match->status === ReconciliationMatchStatus::Confirmed)->count();
        if ($confirmedCount === 0) {
            $warnings[] = [
                'code' => 'CLOSURE_EMPTY_SESSION',
                'message' => 'A sessão não possui nenhum match confirmado.',
            ];
        }

        return new ReconciliationClosureReadiness(ready: $blockers === [], blockers: $blockers, warnings: $warnings);
    }
}
