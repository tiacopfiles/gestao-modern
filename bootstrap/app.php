<?php

use App\Http\Api\ApiErrorResponse;
use App\Http\Api\ApiException;
use App\Http\Middleware\AuthenticateIntegrationClient;
use App\Http\Middleware\EnsureCorrelationId;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsureReconciliationClosingEnabled;
use App\Http\Middleware\EnsureReconciliationMatchingEnabled;
use App\Http\Middleware\EnsureReconciliationV2Enabled;
use App\Http\Middleware\LogIntegrationRequest;
use App\Http\Middleware\RateLimitIntegrationClient;
use App\Http\Middleware\RequireIntegrationScope;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'integration.auth' => AuthenticateIntegrationClient::class,
            'integration.correlation' => EnsureCorrelationId::class,
            'integration.idempotency' => EnsureIdempotentRequest::class,
            'integration.log' => LogIntegrationRequest::class,
            'integration.rate' => RateLimitIntegrationClient::class,
            'integration.scope' => RequireIntegrationScope::class,
            'reconciliation.v2' => EnsureReconciliationV2Enabled::class,
            'reconciliation.matching' => EnsureReconciliationMatchingEnabled::class,
            'reconciliation.closing' => EnsureReconciliationClosingEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception): bool => $request->is('api/*'),
        );

        $exceptions->render(fn (ApiException $exception, Request $request) => $request->is('api/*')
            ? ApiErrorResponse::fromException($request, $exception)
            : null);
        $exceptions->render(fn (ValidationException $exception, Request $request) => $request->is('api/*')
            ? ApiErrorResponse::fromValidation($request, $exception)
            : null);
        $exceptions->render(fn (NotFoundHttpException $exception, Request $request) => $request->is('api/*')
            ? ApiErrorResponse::make($request, 'RESOURCE_NOT_FOUND', 'Recurso não encontrado.', 404)
            : null);
        $exceptions->render(fn (MethodNotAllowedHttpException $exception, Request $request) => $request->is('api/*')
            ? ApiErrorResponse::make($request, 'METHOD_NOT_ALLOWED', 'Método HTTP não permitido para este recurso.', 405)
            : null);
        $exceptions->render(fn (Throwable $exception, Request $request) => $request->is('api/*')
            ? ApiErrorResponse::make($request, 'INTERNAL_ERROR', 'Não foi possível concluir a requisição.', 500)
            : null);
    })->create();
