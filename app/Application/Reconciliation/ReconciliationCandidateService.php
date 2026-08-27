<?php

namespace App\Application\Reconciliation;

use App\Contracts\AuditEventRecorder;
use App\Domain\Reconciliation\Enums\ReconciliationCandidateStatus;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionStatus;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\ReconciliationCandidate;
use App\Models\ReconciliationException;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationCandidateService
{
    use AssertsReconciliationSessionOpenForWrite;

    public function __construct(
        private readonly ReconciliationMatchingFeature $feature,
        private readonly ManualReconciliationService $manual,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function accept(int $sessionId, int $candidateId, int $actorId, string $correlationId): ReconciliationMatch
    {
        $this->feature->assertEnabled();
        try {
            $match = DB::transaction(function () use ($sessionId, $candidateId, $actorId, $correlationId): ReconciliationMatch {
                $session = ReconciliationSession::query()->lockForUpdate()->find($sessionId);
                if (! $session) {
                    throw new ReconciliationRuleViolation('RECONCILIATION_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
                }
                $this->assertSessionOpenForWrite($session);
                $candidate = ReconciliationCandidate::query()->where('reconciliation_session_id', $sessionId)->lockForUpdate()->find($candidateId);
                $this->assertPending($candidate);
                $candidate->load(['titleAllocations', 'transactionAllocations']);
                $titles = $candidate->titleAllocations->map(fn ($item) => new ReconciliationTitleAllocationData(
                    (int) $item->financial_title_id, (int) $item->title_installment_id, (string) $item->proposed_amount,
                ))->values()->all();
                $transactions = $candidate->transactionAllocations->map(fn ($item) => new ReconciliationTransactionAllocationData(
                    (int) $item->bank_transaction_id, (string) $item->proposed_amount,
                ))->values()->all();
                $match = $this->manual->confirm($sessionId, $titles, $transactions, $actorId, $correlationId);
                $before = ['status' => $candidate->status->value];
                $candidate->update([
                    'status' => ReconciliationCandidateStatus::Accepted, 'reconciliation_match_id' => $match->id,
                    'decided_by' => $actorId, 'decided_at' => now(), 'correlation_id' => $correlationId,
                ]);
                $titleIds = $candidate->titleAllocations->pluck('title_installment_id');
                $transactionIds = $candidate->transactionAllocations->pluck('bank_transaction_id');
                ReconciliationException::query()->where('reconciliation_session_id', $sessionId)
                    ->whereIn('status', [ReconciliationExceptionStatus::Open->value, ReconciliationExceptionStatus::InReview->value])
                    ->where(fn ($query) => $query->whereIn('title_installment_id', $titleIds)->orWhereIn('bank_transaction_id', $transactionIds))
                    ->update(['status' => ReconciliationExceptionStatus::Resolved->value, 'resolved_by' => $actorId, 'resolved_at' => now(), 'resolution_reason' => 'Resolvida pela aceitação explícita do candidato #'.$candidate->id, 'correlation_id' => $correlationId, 'updated_at' => now()]);
                $this->audit->record('RECONCILIATION_CANDIDATE_ACCEPTED', ReconciliationCandidate::class, $candidate->id, $before, ['status' => 'ACCEPTED', 'match_id' => $match->id], null, $actorId, $correlationId);

                return $match;
            }, 3);
        } catch (ReconciliationRuleViolation $exception) {
            if (! in_array($exception->rule, ['RECONCILIATION_CANDIDATE_NOT_FOUND', 'RECONCILIATION_CANDIDATE_NOT_PENDING', 'RECONCILIATION_SESSION_CLOSED'], true)) {
                DB::transaction(function () use ($sessionId, $candidateId, $actorId, $correlationId, $exception): void {
                    $candidate = ReconciliationCandidate::query()->where('reconciliation_session_id', $sessionId)->lockForUpdate()->find($candidateId);
                    if ($candidate && $candidate->status === ReconciliationCandidateStatus::Pending) {
                        $candidate->update(['status' => ReconciliationCandidateStatus::Stale, 'decided_by' => $actorId, 'decided_at' => now(), 'decision_reason' => 'Revalidação falhou: '.$exception->rule, 'correlation_id' => $correlationId]);
                        $this->audit->record('RECONCILIATION_CANDIDATE_STALE', ReconciliationCandidate::class, $candidate->id, ['status' => 'PENDING'], ['status' => 'STALE', 'rule' => $exception->rule], null, $actorId, $correlationId);
                    }
                });
                throw new ReconciliationRuleViolation('RECONCILIATION_CANDIDATE_STALE', 'O candidato ficou obsoleto após revalidação. Gere novas sugestões.');
            }
            throw $exception;
        }
        Log::info('reconciliation_matching_operation', ['correlation_id' => $correlationId, 'user_id' => $actorId, 'session_id' => $sessionId, 'candidate_id' => $candidateId, 'match_id' => $match->id, 'action' => 'CANDIDATE_ACCEPTED']);

        return $match;
    }

    public function reject(int $sessionId, int $candidateId, string $reason, int $actorId, string $correlationId): ReconciliationCandidate
    {
        $this->feature->assertEnabled();
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new ReconciliationRuleViolation('RECONCILIATION_REJECTION_REASON_REQUIRED', 'Informe um motivo de 1 a 1000 caracteres.');
        }

        return DB::transaction(function () use ($sessionId, $candidateId, $reason, $actorId, $correlationId): ReconciliationCandidate {
            $session = ReconciliationSession::query()->lockForUpdate()->find($sessionId);
            if (! $session) {
                throw new ReconciliationRuleViolation('RECONCILIATION_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
            }
            $this->assertSessionOpenForWrite($session);
            $candidate = ReconciliationCandidate::query()->where('reconciliation_session_id', $sessionId)->lockForUpdate()->find($candidateId);
            $this->assertPending($candidate);
            $candidate->update(['status' => ReconciliationCandidateStatus::Rejected, 'decided_by' => $actorId, 'decided_at' => now(), 'decision_reason' => $reason, 'correlation_id' => $correlationId]);
            $this->audit->record('RECONCILIATION_CANDIDATE_REJECTED', ReconciliationCandidate::class, $candidate->id, ['status' => 'PENDING'], ['status' => 'REJECTED', 'reason' => $reason], null, $actorId, $correlationId);

            return $candidate->fresh();
        }, 3);
    }

    private function assertPending(?ReconciliationCandidate $candidate): void
    {
        if (! $candidate) {
            throw new ReconciliationRuleViolation('RECONCILIATION_CANDIDATE_NOT_FOUND', 'O candidato não pertence à sessão informada.');
        }
        if ($candidate->status !== ReconciliationCandidateStatus::Pending) {
            throw new ReconciliationRuleViolation('RECONCILIATION_CANDIDATE_NOT_PENDING', 'Somente candidatos pendentes podem ser decididos.');
        }
    }
}
