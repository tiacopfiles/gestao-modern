<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiErrorResponse;
use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitIntegrationClient
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var IntegrationClient|null $client */
        $client = $request->attributes->get('integration_client');
        $maxAttempts = max(1, (int) config('integrations.rate_limit_per_minute', 60));
        $key = 'integration-api:'.($client?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return ApiErrorResponse::make(
                $request,
                'RATE_LIMIT_EXCEEDED',
                'O limite temporário de requisições foi excedido.',
                429,
            )->withHeaders([
                'Retry-After' => (string) RateLimiter::availableIn($key),
                'X-RateLimit-Limit' => (string) $maxAttempts,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        RateLimiter::hit($key, 60);
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($key, $maxAttempts));

        return $response;
    }
}
