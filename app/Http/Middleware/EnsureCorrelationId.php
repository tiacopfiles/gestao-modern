<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiErrorResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $received = trim((string) $request->header('X-Correlation-ID', ''));
        $correlationId = $received !== '' ? $received : (string) Str::uuid();

        if (mb_strlen($correlationId) > 64 || preg_match('/[\x00-\x1F\x7F]/', $correlationId)) {
            $request->attributes->set('correlation_id', (string) Str::uuid());

            return ApiErrorResponse::make(
                $request,
                'VALIDATION_ERROR',
                'O header X-Correlation-ID é inválido.',
                422,
                ['X-Correlation-ID' => ['Use de 1 a 64 caracteres sem caracteres de controle.']],
            );
        }

        $request->attributes->set('correlation_id', $correlationId);
        $response = $next($request);
        $response->headers->set(
            'X-Correlation-ID',
            (string) $request->attributes->get('correlation_id', $correlationId),
        );

        return $response;
    }
}
