<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiErrorResponse;
use App\Http\Api\ApiException;
use App\Http\Api\CanonicalRequestHasher;
use App\Http\Api\RetryableIntegrationResponse;
use App\Models\IntegrationClient;
use App\Models\IntegrationRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureIdempotentRequest
{
    public function __construct(private readonly CanonicalRequestHasher $hasher) {}

    public function handle(Request $request, Closure $next, string $mode = 'atomic'): Response
    {
        /** @var IntegrationClient|null $authenticatedClient */
        $authenticatedClient = $request->attributes->get('integration_client');
        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '') {
            return ApiErrorResponse::make(
                $request,
                'IDEMPOTENCY_KEY_REQUIRED',
                'O header Idempotency-Key é obrigatório para operações mutáveis.',
                400,
            );
        }

        if (mb_strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9._:-]+$/', $key)) {
            return ApiErrorResponse::make(
                $request,
                'VALIDATION_ERROR',
                'O header Idempotency-Key é inválido.',
                422,
                ['Idempotency-Key' => ['Use de 1 a 128 caracteres: letras, números, ponto, hífen, sublinhado ou dois-pontos.']],
            );
        }

        if (! $authenticatedClient) {
            return ApiErrorResponse::make($request, 'UNAUTHENTICATED', 'Credencial de integração ausente ou inválida.', 401);
        }

        $keyHash = hash('sha256', $key);
        $requestHash = $this->hasher->hash($request);
        $correlationId = (string) $request->attributes->get('correlation_id');
        $attempted = false;

        if ($mode === 'detached') {
            return $this->handleDetached(
                $request,
                $next,
                $authenticatedClient,
                $key,
                $keyHash,
                $requestHash,
                $correlationId,
            );
        }

        try {
            return DB::transaction(function () use (
                $request,
                $next,
                $authenticatedClient,
                $key,
                $keyHash,
                $requestHash,
                $correlationId,
                &$attempted,
            ): Response {
                $client = IntegrationClient::query()
                    ->with('sourceSystem')
                    ->lockForUpdate()
                    ->find($authenticatedClient->id);

                if (! $client?->isUsable()) {
                    throw new ApiException('UNAUTHENTICATED', 'A credencial deixou de estar disponível.', 401);
                }

                $record = IntegrationRequest::query()
                    ->where('integration_client_id', $client->id)
                    ->where('idempotency_key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();

                if ($record && $record->request_hash !== $requestHash) {
                    throw new ApiException(
                        'IDEMPOTENCY_KEY_REUSED',
                        'A Idempotency-Key já foi usada com uma requisição diferente.',
                        409,
                    );
                }

                if ($record?->status === IntegrationRequest::STATUS_COMPLETED) {
                    $request->attributes->set('correlation_id', $record->correlation_id);

                    return $this->replay($record);
                }

                if ($record?->status === IntegrationRequest::STATUS_PROCESSING) {
                    throw new ApiException(
                        'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                        'Já existe uma requisição em processamento para esta Idempotency-Key.',
                        409,
                    );
                }

                $values = [
                    'source_system_id' => $client->source_system_id,
                    'idempotency_key_prefix' => substr($key, 0, 12),
                    'request_method' => strtoupper($request->method()),
                    'request_path' => '/'.$request->path(),
                    'request_hash' => $requestHash,
                    'status' => IntegrationRequest::STATUS_PROCESSING,
                    'response_status' => null,
                    'response_body' => null,
                    'failure_code' => null,
                    'correlation_id' => $correlationId,
                    'started_at' => now(),
                    'completed_at' => null,
                ];

                if ($record) {
                    $record->update($values);
                } else {
                    $record = IntegrationRequest::query()->create($values + [
                        'integration_client_id' => $client->id,
                        'idempotency_key_hash' => $keyHash,
                    ]);
                }

                $attempted = true;

                try {
                    $response = $next($request);
                } catch (ValidationException $exception) {
                    $response = ApiErrorResponse::fromValidation($request, $exception);
                } catch (ApiException $exception) {
                    $response = ApiErrorResponse::fromException($request, $exception);
                }

                if ($response->getStatusCode() >= 500) {
                    throw new RetryableIntegrationResponse($response);
                }

                $record->update([
                    'status' => IntegrationRequest::STATUS_COMPLETED,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => (string) $response->getContent(),
                    'completed_at' => now(),
                ]);
                $response->headers->set('Idempotency-Replayed', 'false');

                return $response;
            }, 3);
        } catch (RetryableIntegrationResponse $exception) {
            $this->markFailed($authenticatedClient, $key, $keyHash, $request, $requestHash, $correlationId, 'TRANSIENT_HTTP_ERROR');

            return $exception->response;
        } catch (ApiException $exception) {
            return ApiErrorResponse::fromException($request, $exception);
        } catch (Throwable $exception) {
            if ($attempted) {
                $this->markFailed($authenticatedClient, $key, $keyHash, $request, $requestHash, $correlationId, 'INTERNAL_ERROR');
            }

            throw $exception;
        }
    }

    private function handleDetached(
        Request $request,
        Closure $next,
        IntegrationClient $authenticatedClient,
        string $key,
        string $keyHash,
        string $requestHash,
        string $correlationId,
    ): Response {
        $attempted = false;

        try {
            $prepared = DB::transaction(function () use (
                $request,
                $authenticatedClient,
                $key,
                $keyHash,
                $requestHash,
                $correlationId,
            ): IntegrationRequest|Response {
                $client = IntegrationClient::query()
                    ->with('sourceSystem')
                    ->lockForUpdate()
                    ->find($authenticatedClient->id);
                if (! $client?->isUsable()) {
                    throw new ApiException('UNAUTHENTICATED', 'A credencial deixou de estar disponível.', 401);
                }

                $record = IntegrationRequest::query()
                    ->where('integration_client_id', $client->id)
                    ->where('idempotency_key_hash', $keyHash)
                    ->lockForUpdate()
                    ->first();
                if ($record && $record->request_hash !== $requestHash) {
                    throw new ApiException(
                        'IDEMPOTENCY_KEY_REUSED',
                        'A Idempotency-Key já foi usada com uma requisição diferente.',
                        409,
                    );
                }
                if ($record?->status === IntegrationRequest::STATUS_COMPLETED) {
                    $request->attributes->set('correlation_id', $record->correlation_id);

                    return $this->replay($record);
                }
                if ($record?->status === IntegrationRequest::STATUS_PROCESSING) {
                    throw new ApiException(
                        'IDEMPOTENCY_REQUEST_IN_PROGRESS',
                        'Já existe uma requisição em processamento para esta Idempotency-Key.',
                        409,
                    );
                }

                $values = [
                    'source_system_id' => $client->source_system_id,
                    'idempotency_key_prefix' => substr($key, 0, 12),
                    'request_method' => strtoupper($request->method()),
                    'request_path' => '/'.$request->path(),
                    'request_hash' => $requestHash,
                    'status' => IntegrationRequest::STATUS_PROCESSING,
                    'response_status' => null,
                    'response_body' => null,
                    'failure_code' => null,
                    'correlation_id' => $correlationId,
                    'started_at' => now(),
                    'completed_at' => null,
                ];

                if ($record) {
                    $record->update($values);

                    return $record;
                }

                return IntegrationRequest::query()->create($values + [
                    'integration_client_id' => $client->id,
                    'idempotency_key_hash' => $keyHash,
                ]);
            }, 3);

            if ($prepared instanceof Response) {
                return $prepared;
            }

            $attempted = true;

            try {
                $response = $next($request);
            } catch (ValidationException $exception) {
                $response = ApiErrorResponse::fromValidation($request, $exception);
            } catch (ApiException $exception) {
                $response = ApiErrorResponse::fromException($request, $exception);
            }

            if ($response->getStatusCode() >= 500) {
                $this->markFailed(
                    $authenticatedClient,
                    $key,
                    $keyHash,
                    $request,
                    $requestHash,
                    $correlationId,
                    'TRANSIENT_HTTP_ERROR',
                );

                return $response;
            }

            DB::transaction(function () use ($prepared, $response): void {
                $record = IntegrationRequest::query()->lockForUpdate()->findOrFail($prepared->id);
                $record->update([
                    'status' => IntegrationRequest::STATUS_COMPLETED,
                    'response_status' => $response->getStatusCode(),
                    'response_body' => (string) $response->getContent(),
                    'completed_at' => now(),
                ]);
            }, 3);
            $response->headers->set('Idempotency-Replayed', 'false');

            return $response;
        } catch (ApiException $exception) {
            return ApiErrorResponse::fromException($request, $exception);
        } catch (Throwable $exception) {
            if ($attempted) {
                $this->markFailed(
                    $authenticatedClient,
                    $key,
                    $keyHash,
                    $request,
                    $requestHash,
                    $correlationId,
                    'INTERNAL_ERROR',
                );
            }

            throw $exception;
        }
    }

    private function replay(IntegrationRequest $record): Response
    {
        $body = (string) $record->response_body;
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $decoded['meta'] ??= [];
            if (is_array($decoded['meta'])) {
                $decoded['meta']['idempotency_replayed'] = true;
            }
            $response = response()->json($decoded, (int) $record->response_status);
        } else {
            $response = response($body, (int) $record->response_status, ['Content-Type' => 'application/json']);
        }

        $response->headers->set('Idempotency-Replayed', 'true');

        return $response;
    }

    private function markFailed(
        IntegrationClient $client,
        string $key,
        string $keyHash,
        Request $request,
        string $requestHash,
        string $correlationId,
        string $failureCode,
    ): void {
        DB::transaction(function () use (
            $client,
            $key,
            $keyHash,
            $request,
            $requestHash,
            $correlationId,
            $failureCode,
        ): void {
            IntegrationClient::query()->lockForUpdate()->find($client->id);
            $record = IntegrationRequest::query()
                ->where('integration_client_id', $client->id)
                ->where('idempotency_key_hash', $keyHash)
                ->lockForUpdate()
                ->first();

            if ($record?->status === IntegrationRequest::STATUS_COMPLETED || ($record && $record->request_hash !== $requestHash)) {
                return;
            }

            $values = [
                'source_system_id' => $client->source_system_id,
                'idempotency_key_prefix' => substr($key, 0, 12),
                'request_method' => strtoupper($request->method()),
                'request_path' => '/'.$request->path(),
                'request_hash' => $requestHash,
                'status' => IntegrationRequest::STATUS_FAILED,
                'response_status' => null,
                'response_body' => null,
                'failure_code' => $failureCode,
                'correlation_id' => $correlationId,
                'started_at' => $record?->started_at ?? now(),
                'completed_at' => now(),
            ];

            if ($record) {
                $record->update($values);
            } else {
                IntegrationRequest::query()->create($values + [
                    'integration_client_id' => $client->id,
                    'idempotency_key_hash' => $keyHash,
                ]);
            }
        }, 3);
    }
}
