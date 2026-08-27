<?php

namespace App\Application\Reconciliation;

use App\Contracts\AuditEventRecorder;
use App\Domain\Reconciliation\Enums\ReconciliationSessionStatus;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Models\Conta;
use App\Models\ReconciliationSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconciliationSessionService
{
    public function __construct(
        private readonly ReconciliationFeature $feature,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function create(
        int $accountId,
        string $periodStart,
        string $periodEnd,
        int $actorId,
        string $correlationId,
    ): ReconciliationSession {
        $this->feature->assertEnabled();
        if ($actorId < 1) {
            throw new ReconciliationRuleViolation('RECONCILIATION_ACTOR_REQUIRED', 'O usuário responsável é obrigatório.');
        }
        if (! Conta::query()->whereKey($accountId)->exists()) {
            throw new ReconciliationRuleViolation('RECONCILIATION_ACCOUNT_NOT_FOUND', 'A conta informada não existe ou não está disponível.');
        }

        $start = $this->strictDate($periodStart, 'data inicial');
        $end = $this->strictDate($periodEnd, 'data final');
        if ($end->isBefore($start)) {
            throw new ReconciliationRuleViolation('RECONCILIATION_INVALID_PERIOD', 'A data final deve ser igual ou posterior à data inicial.');
        }

        try {
            $session = DB::transaction(function () use ($accountId, $start, $end, $actorId, $correlationId): ReconciliationSession {
                $duplicate = ReconciliationSession::query()
                    ->where('account_id', $accountId)
                    ->whereDate('period_start', $start->toDateString())
                    ->whereDate('period_end', $end->toDateString())
                    ->lockForUpdate()
                    ->exists();
                if ($duplicate) {
                    throw new ReconciliationRuleViolation(
                        'RECONCILIATION_SESSION_DUPLICATE',
                        'Já existe uma sessão para esta conta e período.',
                    );
                }

                $created = ReconciliationSession::query()->create([
                    'account_id' => $accountId,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'status' => ReconciliationSessionStatus::Open,
                    'created_by' => $actorId,
                    'updated_by' => $actorId,
                    'correlation_id' => $correlationId,
                ]);

                $this->audit->record(
                    'RECONCILIATION_SESSION_CREATED',
                    ReconciliationSession::class,
                    $created->id,
                    null,
                    $this->snapshot($created),
                    null,
                    $actorId,
                    $correlationId,
                );

                return $created->fresh();
            }, 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ReconciliationRuleViolation(
                    'RECONCILIATION_SESSION_DUPLICATE',
                    'Já existe uma sessão para esta conta e período.',
                );
            }

            throw $exception;
        }

        Log::info('reconciliation_v2_operation', [
            'correlation_id' => $correlationId,
            'user_id' => $actorId,
            'session_id' => $session->id,
            'match_id' => null,
            'account_id' => $accountId,
            'action' => 'SESSION_CREATED',
            'status' => $session->status->value,
        ]);

        return $session;
    }

    private function strictDate(string $value, string $label): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            $date = null;
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new ReconciliationRuleViolation('RECONCILIATION_INVALID_PERIOD', "A {$label} deve usar YYYY-MM-DD.");
        }

        return $date;
    }

    /** @return array<string, mixed> */
    private function snapshot(ReconciliationSession $session): array
    {
        return [
            'id' => $session->id,
            'account_id' => $session->account_id,
            'period_start' => $session->period_start->toDateString(),
            'period_end' => $session->period_end->toDateString(),
            'status' => $session->status->value,
            'created_by' => $session->created_by,
        ];
    }
}
