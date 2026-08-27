<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiErrorResponse;
use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateIntegrationClient
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();

        if (! is_string($plainTextToken) || $plainTextToken === '') {
            return ApiErrorResponse::make($request, 'UNAUTHENTICATED', 'Credencial de integração ausente ou inválida.', 401);
        }

        $client = IntegrationClient::query()
            ->with('sourceSystem')
            ->where('token_hash', hash('sha256', $plainTextToken))
            ->first();

        if (! $client || ! $client->active || ($client->expires_at && $client->expires_at->isPast())) {
            return ApiErrorResponse::make($request, 'UNAUTHENTICATED', 'Credencial de integração ausente ou inválida.', 401);
        }

        if (! $client->sourceSystem?->active) {
            return ApiErrorResponse::make($request, 'SOURCE_SYSTEM_INACTIVE', 'O sistema de origem está inativo.', 401);
        }

        $client->forceFill(['last_used_at' => now()])->saveQuietly();
        $request->attributes->set('integration_client', $client);

        return $next($request);
    }
}
