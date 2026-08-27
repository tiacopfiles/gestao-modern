<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiErrorResponse;
use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireIntegrationScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        /** @var IntegrationClient|null $client */
        $client = $request->attributes->get('integration_client');

        if (! $client?->hasScope($scope)) {
            return ApiErrorResponse::make($request, 'FORBIDDEN', 'A credencial não possui o escopo exigido.', 403);
        }

        return $next($request);
    }
}
