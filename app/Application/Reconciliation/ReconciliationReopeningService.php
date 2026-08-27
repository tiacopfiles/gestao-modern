<?php

namespace App\Application\Reconciliation;

use App\Contracts\AuditEventRecorder;
use App\Domain\Reconciliation\Closure\Enums\ReconciliationClosureStatus;
use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Models\ReconciliationClosure;
use App\Models\ReconciliationReopening;
use App\Models\ReconciliationSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationReopeningService
{
    public function __construct(
        private readonly ReconciliationClosingFeature $feature,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function reopen(int $sessionId, int $closureId, string $reason, int $actorId, string $correlationId): ReconciliationClosure
    {
        $this->feature->assertEnabled();
        if ($actorId < 1) {
            throw new ReconciliationRuleViolation('RECONCILIATION_ACTOR_REQUIRED', 'O usuário responsável é obrigatório.');
        }
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new ReconciliationRuleViolation('CLOSURE_REOPEN_REASON_REQUIRED', 'Informe um motivo de 1 a 1000 caracteres para reabrir.');
        }

        $closure = DB::transaction(function () use ($sessionId, $closureId, $reason, $actorId, $correlationId): ReconciliationClosure {
            $session = ReconciliationSession::query()->lockForUpdate()->find($sessionId);
            if (! $session) {
                throw new ReconciliationRuleViolation('CLOSURE_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
            }

            $closure = ReconciliationClosure::query()
                ->where('reconciliation_session_id', $session->id)
                ->lockForUpdate()
                ->find($closureId);
            if (! $closure) {
                throw new ReconciliationRuleViolation('CLOSURE_NOT_FOUND', 'O fechamento não pertence à sessão informada.');
            }
            if ($closure->status !== ReconciliationClosureStatus::Closed) {
                throw new ReconciliationRuleViolation('CLOSURE_NOT_CLOSED', 'Somente um fechamento vigente (CLOSED) pode ser reaberto.');
            }

            $before = ['closure_id' => $closure->id, 'status' => ReconciliationClosureStatus::Closed->value];
            $reopenedAt = now();

            ReconciliationReopening::query()->create([
                'reconciliation_closure_id' => $closure->id,
                'reopened_by' => $actorId,
                'reopened_at' => $reopenedAt,
                'reason' => $reason,
                'previous_status' => ReconciliationClosureStatus::Closed->value,
                'resulting_session_status' => ReconciliationSessionStatus::Reopened->value,
                'correlation_id' => $correlationId,
            ]);

            $closure->update([
                'status' => ReconciliationClosureStatus::Reopened,
                'reopened_by' => $actorId,
                'reopened_at' => $reopenedAt,
            ]);
            $session->update(['status' => ReconciliationSessionStatus::Reopened, 'updated_by' => $actorId]);

            $this->audit->record(
                'RECONCILIATION_CLOSURE_REOPENED',
                ReconciliationClosure::class,
                $closure->id,
                $before,
                [
                    'closure_id' => $closure->id,
                    'status' => ReconciliationClosureStatus::Reopened->value,
                    'reopened_by' => $actorId,
                    'reopened_at' => $reopenedAt->toIso8601String(),
                    'reason' => $reason,
                ],
                null,
                $actorId,
                $correlationId,
            );

            return $closure->fresh(['reopenings']);
        }, 3);

        Log::info('reconciliation_closure_operation', [
            'correlation_id' => $correlationId, 'user_id' => $actorId,
            'session_id' => $sessionId, 'closure_id' => $closure->id, 'action' => 'REOPENED',
        ]);

        return $closure;
    }
}
