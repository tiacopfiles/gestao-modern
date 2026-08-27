<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Banking\CanonicalBankTransactionService;
use App\Domain\Banking\BankTransactionData;
use App\Domain\Banking\Enums\BankTransactionDirection;
use App\Domain\Banking\Exceptions\BankAccountNotFound;
use App\Domain\Banking\Exceptions\BankTransactionIdentityConflict;
use App\Http\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BankTransactionRequest;
use App\Http\Resources\Api\V1\BankTransactionResource;
use App\Models\BankTransaction;
use App\Models\IntegrationClient;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankTransactionController extends Controller
{
    public function __construct(private readonly CanonicalBankTransactionService $transactions) {}

    public function store(BankTransactionRequest $request): JsonResponse
    {
        $client = $this->client($request);
        $data = $request->validated();
        $counterparty = $data['counterparty'] ?? [];
        $rawHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        try {
            $result = $this->transactions->ingest(new BankTransactionData(
                accountId: $data['account_id'],
                sourceSystemId: 0,
                importBatchId: 0,
                externalId: $data['external_id'],
                direction: BankTransactionDirection::from($data['direction']),
                amount: $data['amount'],
                currency: $data['currency'],
                transactionDate: $data['transaction_date'],
                descriptionOriginal: $data['description'],
                postedAt: $data['posted_at'] ?? null,
                documentNumber: $data['document_number'] ?? null,
                bankReference: $data['bank_reference'] ?? null,
                endToEndId: $data['end_to_end_id'] ?? null,
                counterpartyName: $counterparty['name'] ?? null,
                counterpartyDocument: $counterparty['document'] ?? null,
                balanceAfter: $data['balance_after'] ?? null,
                rawHash: $rawHash,
            ), $client, $this->correlationId($request));
        } catch (DomainException $exception) {
            throw $this->domainException($exception);
        }

        $transaction = $result->batch->items()->with('bankTransaction')->firstOrFail()->bankTransaction;
        $created = $result->batch->imported_items === 1;

        return response()->json([
            'data' => (new BankTransactionResource($transaction))->resolve($request),
            'meta' => [
                'correlation_id' => $this->correlationId($request),
                'idempotency_replayed' => false,
                'decision' => $created ? 'CREATED' : 'DUPLICATE',
                'import_batch_id' => $result->batch->id,
            ],
        ], $created ? 201 : 200);
    }

    public function show(Request $request, int $accountId, string $externalId): JsonResponse
    {
        $client = $this->client($request);
        $transaction = BankTransaction::query()
            ->where('source_system_id', $client->source_system_id)
            ->where('account_id', $accountId)
            ->where('external_id', $externalId)
            ->first();

        if (! $transaction) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Transação bancária não encontrada.', 404);
        }

        return response()->json([
            'data' => (new BankTransactionResource($transaction))->resolve($request),
            'meta' => ['correlation_id' => $this->correlationId($request)],
        ]);
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
            $exception instanceof BankTransactionIdentityConflict => new ApiException('BANK_TRANSACTION_ID_CONFLICT', $exception->getMessage(), 409),
            default => new ApiException('DOMAIN_CONFLICT', $exception->getMessage(), 409),
        };
    }
}
