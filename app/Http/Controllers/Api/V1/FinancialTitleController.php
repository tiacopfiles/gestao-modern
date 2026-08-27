<?php

namespace App\Http\Controllers\Api\V1;

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleCancellationService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\IngestionDecision;
use App\Domain\Financial\Exceptions\TitleAlreadySettled;
use App\Domain\Financial\Exceptions\TitleCancellationNotAllowed;
use App\Domain\Financial\Exceptions\TitleTypeChangeNotAllowed;
use App\Domain\Financial\Exceptions\TitleUpdateNotAllowed;
use App\Domain\Financial\Money;
use App\Domain\Financial\TitleIngestionData;
use App\Http\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CancelFinancialTitleRequest;
use App\Http\Requests\Api\V1\FinancialTitleRequest;
use App\Http\Requests\Api\V1\SettleFinancialTitleRequest;
use App\Http\Resources\Api\V1\FinancialTitleResource;
use App\Models\FinancialTitle;
use App\Models\IntegrationClient;
use App\Models\TitleSettlement;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialTitleController extends Controller
{
    public function __construct(
        private readonly TitleIngestionService $ingestion,
        private readonly TitleCancellationService $cancellations,
        private readonly SettlementService $settlements,
    ) {}

    public function store(FinancialTitleRequest $request): JsonResponse
    {
        try {
            $result = $this->ingestion->ingest(
                $this->toData($request, (string) $request->validated('external_id')),
                correlationId: $this->correlationId($request),
                integrationClientId: $this->client($request)->id,
            );
        } catch (DomainException $exception) {
            throw $this->domainException($exception);
        }

        return response()->json([
            'data' => $this->resource($request, $result->title),
            'meta' => [
                'correlation_id' => $this->correlationId($request),
                'idempotency_replayed' => false,
                'decision' => $result->decision->value,
            ],
        ], $result->decision === IngestionDecision::Created ? 201 : 200);
    }

    public function show(Request $request, string $externalId): JsonResponse
    {
        $title = $this->findTitle($request, $externalId);

        return response()->json([
            'data' => $this->resource($request, $title),
            'meta' => ['correlation_id' => $this->correlationId($request)],
        ]);
    }

    public function update(FinancialTitleRequest $request, string $externalId): JsonResponse
    {
        $this->findTitle($request, $externalId);

        try {
            $result = $this->ingestion->ingest(
                $this->toData($request, $externalId),
                correlationId: $this->correlationId($request),
                integrationClientId: $this->client($request)->id,
            );
        } catch (DomainException $exception) {
            throw $this->domainException($exception);
        }

        return response()->json([
            'data' => $this->resource($request, $result->title),
            'meta' => [
                'correlation_id' => $this->correlationId($request),
                'idempotency_replayed' => false,
                'decision' => $result->decision->value,
            ],
        ]);
    }

    public function cancel(CancelFinancialTitleRequest $request, string $externalId): JsonResponse
    {
        $client = $this->client($request);
        $title = $this->findTitle($request, $externalId);

        try {
            $result = $this->cancellations->cancel(
                $title->id,
                $client->source_system_id,
                $client->id,
                (string) $request->validated('reason'),
                $this->correlationId($request),
            );
        } catch (DomainException $exception) {
            throw $this->domainException($exception);
        }

        return response()->json([
            'data' => $this->resource($request, $result->title),
            'meta' => [
                'correlation_id' => $this->correlationId($request),
                'idempotency_replayed' => false,
                'decision' => $result->alreadyCancelled ? 'IGNORED' : 'CANCELLED',
            ],
        ]);
    }

    /**
     * Registra a realização (pagamento/recebimento) informada pela origem.
     *
     * Este é o ponto que fecha o ciclo "a funcionária deu baixa no Contas a
     * Pagar" → "o Gestão sabe que o título foi realizado". Não cria conciliação:
     * o título passa a REALIZADO e fica disponível para ser conciliado contra o
     * fato bancário quando ele chegar.
     */
    public function settle(SettleFinancialTitleRequest $request, string $externalId): JsonResponse
    {
        $client = $this->client($request);
        $title = $this->findTitle($request, $externalId);

        $installmentId = null;
        if ($request->filled('installment_number')) {
            $number = (int) $request->validated('installment_number');
            $installment = $title->installments->firstWhere('installment_number', $number);
            if (! $installment) {
                throw new ApiException('RESOURCE_NOT_FOUND', "Parcela {$number} não existe neste título.", 404);
            }
            $installmentId = $installment->id;
        }

        $externalId = $request->validated('external_id');

        // Replay: a origem reenviou a mesma baixa (timeout, retry, reprocessamento
        // de fila). Precisa ser resolvido ANTES de calcular o valor — depois da
        // primeira liquidação o saldo restante é zero, e recalcular produziria um
        // payload diferente, fazendo o serviço recusar por conflito. O reenvio de
        // uma baixa já registrada tem que ser sucesso, nunca erro.
        if ($externalId !== null && $externalId !== '') {
            $existing = TitleSettlement::query()
                ->where('source_system_id', $client->source_system_id)
                ->where('external_id', $externalId)
                ->first();

            if ($existing) {
                return $this->settlementResponse($request, $title->fresh(['installments', 'cancellation']), $existing, replayed: true);
            }
        }

        // Sem valor explícito, liquida o saldo restante — o caso normal de uma
        // baixa vinda da origem, que paga o título inteiro.
        $amount = $request->filled('amount')
            ? (string) $request->validated('amount')
            : $this->remainingAmount($title, $installmentId);

        try {
            $settlement = $this->settlements->settle(
                titleId: $title->id,
                amount: $amount,
                settlementDate: (string) $request->validated('settlement_date'),
                installmentId: $installmentId,
                sourceSystemId: $client->source_system_id,
                externalId: $externalId,
                idempotencyKey: 'api:'.$client->id.':'.hash('sha256', (string) $request->header('Idempotency-Key')),
            );
        } catch (DomainException $exception) {
            throw $this->domainException($exception);
        }

        return $this->settlementResponse($request, $title->fresh(['installments', 'cancellation']), $settlement, replayed: false);
    }

    private function settlementResponse(
        Request $request,
        FinancialTitle $title,
        TitleSettlement $settlement,
        bool $replayed,
    ): JsonResponse {
        return response()->json([
            'data' => $this->resource($request, $title),
            'meta' => [
                'correlation_id' => $this->correlationId($request),
                'idempotency_replayed' => $replayed,
                'decision' => $replayed ? 'ALREADY_SETTLED' : 'SETTLED',
                'settlement' => [
                    'id' => $settlement->id,
                    'settlement_date' => $settlement->settlement_date->toDateString(),
                    'amount' => (string) $settlement->amount,
                    'type' => $settlement->type->value,
                ],
            ],
        ], $replayed ? 200 : 201);
    }

    private function remainingAmount(FinancialTitle $title, ?int $installmentId): string
    {
        $cents = $installmentId !== null
            ? $title->installments->firstWhere('id', $installmentId)->remainingCents()
            : $title->remainingCents();

        return Money::fromCents($cents);
    }

    private function findTitle(Request $request, string $externalId): FinancialTitle
    {
        $client = $this->client($request);
        $title = FinancialTitle::query()
            ->with(['installments', 'cancellation'])
            ->where('source_system_id', $client->source_system_id)
            ->where('type', $this->titleType($request))
            ->where('external_id', $externalId)
            ->first();

        if (! $title) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Título financeiro não encontrado.', 404);
        }

        return $title;
    }

    private function toData(FinancialTitleRequest $request, string $externalId): TitleIngestionData
    {
        $data = $request->validated();
        $party = $data['party'] ?? [];
        $client = $this->client($request);
        $httpIdempotencyKey = (string) $request->header('Idempotency-Key');

        return new TitleIngestionData(
            sourceCode: $client->sourceSystem->code,
            externalId: $externalId,
            type: $this->titleType($request),
            issueDate: $data['issue_date'],
            dueDate: $data['due_date'],
            originalAmount: $data['original_amount'],
            discountAmount: $data['discount_amount'],
            additionAmount: $data['addition_amount'],
            partyId: $party['id'] ?? null,
            partyType: $party['type'] ?? null,
            partyName: $party['name'] ?? null,
            documentNumber: $data['document_number'] ?? null,
            accountId: $data['account_id'] ?? null,
            categoryId: $data['category_id'] ?? null,
            costCenterId: $data['cost_center_id'] ?? null,
            currency: $data['currency'],
            notes: $data['notes'] ?? null,
            installmentCount: $data['installment_count'],
            idempotencyKey: 'api:'.$client->id.':'.hash('sha256', $httpIdempotencyKey),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resource(Request $request, FinancialTitle $title): array
    {
        return (new FinancialTitleResource($title->loadMissing(['installments', 'cancellation'])))->resolve($request);
    }

    private function titleType(Request $request): FinancialTitleType
    {
        return FinancialTitleType::from((string) $request->route('financial_title_type'));
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
            $exception instanceof TitleAlreadySettled => new ApiException('TITLE_ALREADY_SETTLED', $exception->getMessage(), 409),
            $exception instanceof TitleCancellationNotAllowed => new ApiException('TITLE_CANCEL_NOT_ALLOWED', $exception->getMessage(), 409),
            $exception instanceof TitleTypeChangeNotAllowed,
            $exception instanceof TitleUpdateNotAllowed => new ApiException('TITLE_UPDATE_NOT_ALLOWED', $exception->getMessage(), 409),
            default => new ApiException('DOMAIN_CONFLICT', $exception->getMessage(), 409),
        };
    }
}
