<?php

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApiErrorResponse
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(
        Request $request,
        string $code,
        string $message,
        int $status,
        array $details = [],
    ): JsonResponse {
        $correlationId = (string) ($request->attributes->get('correlation_id') ?: Str::uuid());
        $request->attributes->set('correlation_id', $correlationId);

        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => (object) $details,
                'correlation_id' => $correlationId,
            ],
        ], $status)->header('X-Correlation-ID', $correlationId);
    }

    public static function fromException(Request $request, ApiException $exception): JsonResponse
    {
        return self::make(
            $request,
            $exception->errorCode,
            $exception->getMessage(),
            $exception->status,
            $exception->details,
        );
    }

    public static function fromValidation(Request $request, ValidationException $exception): JsonResponse
    {
        return self::make(
            $request,
            'VALIDATION_ERROR',
            'A requisição possui campos inválidos.',
            422,
            $exception->errors(),
        );
    }
}
