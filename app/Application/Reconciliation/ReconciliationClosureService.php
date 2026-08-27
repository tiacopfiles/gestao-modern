<?php

namespace App\Application\Reconciliation;

use App\Contracts\AuditEventRecorder;
use App\Domain\Reconciliation\Closure\Enums\ReconciliationClosureStatus;
use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Models\ReconciliationClosure;
use App\Models\ReconciliationClosureException;
use App\Models\ReconciliationClosureMatch;
use App\Models\ReconciliationClosureMetric;
use App\Models\ReconciliationException;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconciliationClosureService
{
    public function __construct(
        private readonly ReconciliationClosingFeature $feature,
        private readonly ReconciliationClosureValidator $validator,
        private readonly ReconciliationClosureSnapshotBuilder $snapshotBuilder,
        private readonly ReconciliationClosureHashService $hashService,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function close(int $sessionId, int $actorId, string $correlationId): ReconciliationClosure
    {
        $this->feature->assertEnabled();
        if ($actorId < 1) {
            throw new ReconciliationRuleViolation('RECONCILIATION_ACTOR_REQUIRED', 'O usuário responsável é obrigatório.');
        }

        return DB::transaction(function () use ($sessionId, $actorId, $correlationId): ReconciliationClosure {
            $session = ReconciliationSession::query()->lockForUpdate()->find($sessionId);
            if (! $session) {
                throw new ReconciliationRuleViolation('CLOSURE_SESSION_NOT_FOUND', 'A sessão de conciliação não existe.');
            }

            $this->audit->record(
                'RECONCILIATION_CLOSURE_CREATED',
                ReconciliationSession::class,
                $session->id,
                null,
                [
                    'session_id' => $session->id,
                    'account_id' => $session->account_id,
                    'period_start' => $session->period_start->format('Y-m-d'),
                    'period_end' => $session->period_end->format('Y-m-d'),
                ],
                null,
                $actorId,
                $correlationId,
            );

            // Ordem estável (sessão → recursos relacionados por ID crescente), mesmo
            // racional de ADR-009. Serializa close() contra confirm/void/accept/reject/justify
            // na mesma sessão, e contra outro close() sobreposto da mesma conta.
            ReconciliationMatch::query()->where('reconciliation_session_id', $session->id)
                ->orderBy('id')->lockForUpdate()->get();
            ReconciliationException::query()->where('reconciliation_session_id', $session->id)
                ->orderBy('id')->lockForUpdate()->get();
            ReconciliationClosure::query()
                ->where('account_id', $session->account_id)
                ->where('status', ReconciliationClosureStatus::Closed->value)
                ->where('reconciliation_session_id', '!=', $session->id)
                ->where('period_start', '<=', $session->period_end)
                ->where('period_end', '>=', $session->period_start)
                ->orderBy('id')->lockForUpdate()->get();

            $readiness = $this->validator->readiness($session);
            if (! $readiness->ready) {
                $blocker = $readiness->blockers[0];
                throw new ReconciliationRuleViolation($blocker['code'], $blocker['message']);
            }

            $built = $this->snapshotBuilder->build($session);
            $payload = $built['payload'];
            $metrics = $built['metrics'];
            $hash = $this->hashService->hash($payload);

            $previousClosure = ReconciliationClosure::query()
                ->where('reconciliation_session_id', $session->id)
                ->orderByDesc('sequence_number')
                ->first();
            $sequenceNumber = $previousClosure ? ((int) $previousClosure->sequence_number) + 1 : 1;

            $closure = ReconciliationClosure::query()->create([
                'reconciliation_session_id' => $session->id,
                'account_id' => $session->account_id,
                'period_start' => $session->period_start,
                'period_end' => $session->period_end,
                'sequence_number' => $sequenceNumber,
                'status' => ReconciliationClosureStatus::Closed,
                'schema_version' => $payload['schema_version'],
                'engine_version' => $payload['engine_version'],
                'closure_hash' => $hash,
                'hash_algorithm' => 'sha256',
                'snapshot_payload' => $payload,
                'previous_closure_id' => $previousClosure?->id,
                'closed_by' => $actorId,
                'closed_at' => now(),
                'correlation_id' => $correlationId,
            ]);

            foreach ($payload['matches'] as $row) {
                ReconciliationClosureMatch::query()->create([
                    'reconciliation_closure_id' => $closure->id,
                    'reconciliation_match_id' => $row['match_id'],
                    'captured_status' => $row['status'],
                    'captured_total_amount' => $row['total_amount'],
                ]);
            }
            foreach ($payload['exceptions'] as $row) {
                ReconciliationClosureException::query()->create([
                    'reconciliation_closure_id' => $closure->id,
                    'reconciliation_exception_id' => $row['exception_id'],
                    'captured_status' => $row['status'],
                    'captured_type' => $row['type'],
                ]);
            }
            foreach ($metrics as $key => $value) {
                ReconciliationClosureMetric::query()->create([
                    'reconciliation_closure_id' => $closure->id,
                    'metric_key' => $key,
                    'metric_value' => $value,
                ]);
            }

            $session->update(['status' => ReconciliationSessionStatus::Closed, 'updated_by' => $actorId]);

            if ($sequenceNumber === 1) {
                $this->audit->record(
                    'RECONCILIATION_CLOSURE_COMPLETED',
                    ReconciliationClosure::class,
                    $closure->id,
                    null,
                    [
                        'closure_id' => $closure->id,
                        'sequence_number' => $sequenceNumber,
                        'closure_hash' => $hash,
                        'engine_version' => $payload['engine_version'],
                        'matches_count' => count($payload['matches']),
                        'exceptions_count' => count($payload['exceptions']),
                        'closed_by' => $actorId,
                        'closed_at' => $closure->closed_at->toIso8601String(),
                    ],
                    null,
                    $actorId,
                    $correlationId,
                );
            } else {
                $this->audit->record(
                    'RECONCILIATION_CLOSURE_RECLOSED',
                    ReconciliationClosure::class,
                    $closure->id,
                    ['previous_closure_id' => $previousClosure->id, 'previous_sequence_number' => (int) $previousClosure->sequence_number],
                    ['closure_id' => $closure->id, 'sequence_number' => $sequenceNumber, 'closure_hash' => $hash],
                    null,
                    $actorId,
                    $correlationId,
                );
            }

            Log::info('reconciliation_closure_operation', [
                'correlation_id' => $correlationId, 'user_id' => $actorId,
                'session_id' => $session->id, 'closure_id' => $closure->id,
                'sequence_number' => $sequenceNumber, 'action' => 'CLOSED',
            ]);

            return $closure->fresh(['matches', 'exceptions', 'metrics']);
        }, 3);
    }
}
