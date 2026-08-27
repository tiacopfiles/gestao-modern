<?php

namespace App\Infrastructure\Audit;

use App\Contracts\AuditEventRecorder;
use App\Models\AuditEvent;

class DatabaseAuditEventRecorder implements AuditEventRecorder
{
    public function record(
        string $action,
        string $entityType,
        int|string $entityId,
        ?array $before,
        ?array $after,
        ?int $sourceSystemId,
        ?int $actorId,
        string $correlationId,
        ?int $integrationClientId = null,
    ): void {
        AuditEvent::query()->create([
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
            'before_state' => $before,
            'after_state' => $after,
            'source_system_id' => $sourceSystemId,
            'integration_client_id' => $integrationClientId,
            'correlation_id' => $correlationId,
            'occurred_at' => now(),
        ]);
    }
}
