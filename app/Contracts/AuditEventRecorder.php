<?php

namespace App\Contracts;

interface AuditEventRecorder
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
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
    ): void;
}
