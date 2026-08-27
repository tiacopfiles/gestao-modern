<?php

namespace App\Http\Middleware;

use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LogIntegrationRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
            $this->write($request, $response->getStatusCode(), $startedAt, $response->headers->get('Idempotency-Replayed') === 'true');

            return $response;
        } catch (Throwable $exception) {
            $this->write($request, 500, $startedAt, false, $exception::class);

            throw $exception;
        }
    }

    private function write(
        Request $request,
        int $status,
        int $startedAt,
        bool $replayed,
        ?string $exceptionClass = null,
    ): void {
        /** @var IntegrationClient|null $client */
        $client = $request->attributes->get('integration_client');
        $externalId = $request->route('external_id') ?: $request->input('external_id');

        Log::info('integration_api_request', array_filter([
            'correlation_id' => $request->attributes->get('correlation_id'),
            'integration_client_id' => $client?->id,
            'source_system' => $client?->sourceSystem?->code,
            'route' => $request->route()?->getName() ?: '/'.$request->path(),
            'method' => $request->method(),
            'external_id' => is_scalar($externalId) ? (string) $externalId : null,
            'result' => $status < 400 ? 'SUCCESS' : 'ERROR',
            'http_status' => $status,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'idempotency_replayed' => $replayed,
            'exception_class' => $exceptionClass,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
