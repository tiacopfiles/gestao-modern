<?php

namespace App\Application\Reconciliation;

use App\Contracts\AuditEventRecorder;
use App\Domain\Reconciliation\Enums\ReconciliationExceptionStatus;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Models\ReconciliationException;
use App\Models\ReconciliationSession;
use Illuminate\Support\Facades\DB;

class ReconciliationExceptionService
{
    use AssertsReconciliationSessionOpenForWrite;

    public function __construct(private readonly ReconciliationMatchingFeature $feature, private readonly AuditEventRecorder $audit) {}

    public function justify(int $sessionId, int $exceptionId, string $reason, int $actorId, string $correlationId): ReconciliationException
    {
        $this->feature->assertEnabled();
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new ReconciliationRuleViolation('RECONCILIATION_JUSTIFICATION_REQUIRED', 'Informe uma justificativa de 1 a 1000 caracteres.');
        }

        return DB::transaction(function () use ($sessionId, $exceptionId, $reason, $actorId, $correlationId): ReconciliationException {
            $session = ReconciliationSession::query()->lockForUpdate()->find($sessionId);
            if (! $session) {
                throw new ReconciliationRuleViolation('RECONCILIATION_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
            }
            $this->assertSessionOpenForWrite($session);
            $exception = ReconciliationException::query()->where('reconciliation_session_id', $sessionId)->lockForUpdate()->find($exceptionId);
            if (! $exception) {
                throw new ReconciliationRuleViolation('RECONCILIATION_EXCEPTION_NOT_FOUND', 'A divergência não pertence à sessão informada.');
            }
            if ($exception->status === ReconciliationExceptionStatus::Resolved) {
                throw new ReconciliationRuleViolation('RECONCILIATION_EXCEPTION_RESOLVED', 'A divergência já foi resolvida por uma conciliação.');
            }
            $before = ['status' => $exception->status->value];
            $exception->update(['status' => ReconciliationExceptionStatus::Justified, 'resolved_by' => $actorId, 'resolved_at' => now(), 'resolution_reason' => $reason, 'correlation_id' => $correlationId]);
            $this->audit->record('RECONCILIATION_EXCEPTION_JUSTIFIED', ReconciliationException::class, $exception->id, $before, ['status' => 'JUSTIFIED', 'reason' => $reason], null, $actorId, $correlationId);

            return $exception->fresh();
        }, 3);
    }
}
