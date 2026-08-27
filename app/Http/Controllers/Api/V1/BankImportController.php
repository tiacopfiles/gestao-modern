<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Banking\OfxImportService;
use App\Domain\Banking\Exceptions\BankAccountNotFound;
use App\Domain\Banking\Exceptions\BankImportInvalidFile;
use App\Domain\Banking\Exceptions\BankImportTooLarge;
use App\Domain\Banking\Exceptions\BankImportUnsupportedFormat;
use App\Http\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OfxImportRequest;
use App\Http\Resources\Api\V1\ImportBatchItemResource;
use App\Http\Resources\Api\V1\ImportBatchResource;
use App\Models\ImportBatch;
use App\Models\IntegrationClient;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class BankImportController extends Controller
{
    public function __construct(private readonly OfxImportService $imports) {}

    public function storeOfx(OfxImportRequest $request): JsonResponse
    {
        $client = $this->client($request);
        /** @var UploadedFile $file */
        $file = $request->file('file');

        try {
            $result = $this->imports->import(
                $file,
                (int) $request->validated('account_id'),
                $client,
                $this->correlationId($request),
            );
        } catch (DomainException $exception) {
            throw $this->domainException($exception);
        }

        return response()->json([
            'data' => (new ImportBatchResource($result->batch))->resolve($request),
            'meta' => [
                'correlation_id' => $this->correlationId($request),
                'idempotency_replayed' => false,
                'decision' => $result->duplicateFile ? 'FILE_DUPLICATE' : 'IMPORTED',
            ],
        ], $result->duplicateFile ? 200 : 201);
    }

    public function show(Request $request, int $batch): JsonResponse
    {
        $record = $this->findBatch($request, $batch);

        return response()->json([
            'data' => (new ImportBatchResource($record))->resolve($request),
            'meta' => ['correlation_id' => $this->correlationId($request)],
        ]);
    }

    public function items(Request $request, int $batch): JsonResponse
    {
        $record = $this->findBatch($request, $batch);
        $items = $record->items()->paginate(50);

        return response()->json([
            'data' => ImportBatchItemResource::collection($items->getCollection())->resolve($request),
            'meta' => [
                'correlation_id' => $this->correlationId($request),
                'pagination' => [
                    'current_page' => $items->currentPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                    'last_page' => $items->lastPage(),
                ],
            ],
        ]);
    }

    private function findBatch(Request $request, int $id): ImportBatch
    {
        $batch = ImportBatch::query()
            ->where('source_system_id', $this->client($request)->source_system_id)
            ->find($id);
        if (! $batch) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Lote bancário não encontrado.', 404);
        }

        return $batch;
    }

    private function client(Request $request): IntegrationClient
    {
        /** @var IntegrationClient $client */
        $client = $request->attributes->get('integration_client');

        return $client;
    }

    private function correlationId(Request $request): string
    {
        return (string) $request->attributes->get('correlation_id');
    }

    private function domainException(DomainException $exception): ApiException
    {
        return match (true) {
            $exception instanceof BankAccountNotFound => new ApiException('BANK_ACCOUNT_NOT_FOUND', $exception->getMessage(), 422),
            $exception instanceof BankImportTooLarge => new ApiException('BANK_IMPORT_TOO_LARGE', $exception->getMessage(), 413),
            $exception instanceof BankImportUnsupportedFormat => new ApiException('BANK_IMPORT_UNSUPPORTED_FORMAT', $exception->getMessage(), 422),
            $exception instanceof BankImportInvalidFile => new ApiException('BANK_IMPORT_INVALID_FILE', $exception->getMessage(), 422),
            default => new ApiException('BANK_IMPORT_FAILED', 'A importação bancária não pôde ser concluída.', 500),
        };
    }
}
