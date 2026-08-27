<?php

namespace App\Application\Financial;

use App\Contracts\AuditEventRecorder;
use App\Domain\Financial\Enums\IngestionDecision;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Exceptions\TitleTypeChangeNotAllowed;
use App\Domain\Financial\Exceptions\TitleUpdateNotAllowed;
use App\Domain\Financial\IngestionResult;
use App\Domain\Financial\Money;
use App\Domain\Financial\TitleIngestionData;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TitleIngestionService
{
    public function __construct(
        private readonly InstallmentScheduleService $installments,
        private readonly AuditEventRecorder $audit,
    ) {}

    public function ingest(
        TitleIngestionData $data,
        ?int $actorId = null,
        ?string $correlationId = null,
        ?int $integrationClientId = null,
    ): IngestionResult {
        $correlationId ??= (string) Str::uuid();

        return DB::transaction(function () use ($data, $actorId, $correlationId, $integrationClientId): IngestionResult {
            $sourceCode = Str::upper(trim($data->sourceCode));
            $source = SourceSystem::query()
                ->where('code', $sourceCode)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $source) {
                throw new DomainException('Sistema de origem inexistente ou inativo.');
            }

            $externalId = $this->nullableString($data->externalId, 128, 'external_id');
            $idempotencyKey = $this->nullableString($data->idempotencyKey, 128, 'Idempotency-Key');
            $legacyType = $this->nullableString($data->legacyType, 30, 'legacy_type');

            if ($source->code !== 'MANUAL' && $externalId === null) {
                throw new DomainException('external_id é obrigatório para origens externas.');
            }
            if (($legacyType === null) !== ($data->legacyId === null)) {
                throw new DomainException('legacy_type e legacy_id devem ser informados em conjunto.');
            }
            if ($data->legacyId !== null && $data->legacyId < 1) {
                throw new DomainException('legacy_id deve ser um identificador positivo.');
            }

            $attributes = $this->normalizedAttributes(
                $data,
                $source,
                $externalId,
                $idempotencyKey,
                $legacyType,
            );
            $payloadHash = $this->payloadHash($attributes, $data->installmentCount);
            $attributes['payload_hash'] = $payloadHash;
            $existing = $this->findExisting(
                $source,
                $externalId,
                $idempotencyKey,
                $legacyType,
                $data->legacyId,
            );

            if ($existing) {
                if ($existing->type !== $data->type) {
                    throw new TitleTypeChangeNotAllowed('O tipo de um título existente não pode ser alterado.');
                }

                if ($existing->payload_hash === $payloadHash) {
                    return new IngestionResult(IngestionDecision::Ignored, $existing->load('installments'));
                }

                if ($idempotencyKey !== null && $existing->idempotency_key === $idempotencyKey) {
                    throw new DomainException('A Idempotency-Key já foi usada com conteúdo diferente.');
                }

                if ($existing->settlements()->exists() || $existing->status === TitleStatus::Cancelled) {
                    $financeiro = $this->camposFinanceirosAlterados(
                        $existing,
                        $attributes,
                        $data->installmentCount,
                    );

                    if ($financeiro !== []) {
                        throw new TitleUpdateNotAllowed(
                            'Título liquidado ou cancelado não pode ter '
                            .implode(', ', $financeiro).' alterado por reenvio.'
                        );
                    }

                    return $this->atualizarDescricao(
                        $existing,
                        $attributes,
                        $source,
                        $actorId,
                        $correlationId,
                        $integrationClientId,
                    );
                }

                $before = $existing->attributesToArray();
                // A primeira chave de requisição associada ao título permanece
                // reservada. Reenvios e atualizações são identificados também
                // pelo external_id, sem liberar uma chave já consumida.
                $attributes['idempotency_key'] = $existing->idempotency_key ?? $idempotencyKey;
                $existing->update($attributes);
                $this->syncInstallments($existing, $data->installmentCount);
                $this->audit->record(
                    'FINANCIAL_TITLE_UPDATED',
                    FinancialTitle::class,
                    $existing->id,
                    $before,
                    $existing->fresh()->attributesToArray(),
                    $source->id,
                    $actorId,
                    $correlationId,
                    $integrationClientId,
                );

                return new IngestionResult(IngestionDecision::Updated, $existing->fresh('installments'));
            }

            $title = FinancialTitle::query()->create($attributes);
            $this->syncInstallments($title, $data->installmentCount);
            $this->audit->record(
                'FINANCIAL_TITLE_CREATED',
                FinancialTitle::class,
                $title->id,
                null,
                $title->fresh()->attributesToArray(),
                $source->id,
                $actorId,
                $correlationId,
                $integrationClientId,
            );

            return new IngestionResult(IngestionDecision::Created, $title->fresh('installments'));
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedAttributes(
        TitleIngestionData $data,
        SourceSystem $source,
        ?string $externalId,
        ?string $idempotencyKey,
        ?string $legacyType,
    ): array {
        $issueDate = CarbonImmutable::parse($data->issueDate)->toDateString();
        $dueDate = CarbonImmutable::parse($data->dueDate)->toDateString();
        if ($dueDate < $issueDate) {
            throw new DomainException('O vencimento não pode ser anterior à emissão.');
        }

        $originalCents = Money::toCents($data->originalAmount);
        $discountCents = Money::toCents($data->discountAmount);
        $additionCents = Money::toCents($data->additionAmount);
        $totalCents = $originalCents - $discountCents + $additionCents;
        if ($originalCents <= 0 || $discountCents < 0 || $additionCents < 0 || $totalCents <= 0) {
            throw new DomainException('Os valores do título são inválidos.');
        }

        $currency = Str::upper(trim($data->currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new DomainException('A moeda deve usar um código ISO de três letras.');
        }

        return [
            'type' => $data->type,
            'source_system_id' => $source->id,
            'external_id' => $externalId,
            'idempotency_key' => $idempotencyKey,
            'party_type' => $this->nullableString($data->partyType, 30, 'party_type'),
            'party_id' => $data->partyId,
            'party_name' => $this->nullableString($data->partyName, 191, 'party_name'),
            'document_number' => $this->nullableString($data->documentNumber, 120, 'document_number'),
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'original_amount' => Money::fromCents($originalCents),
            'discount_amount' => Money::fromCents($discountCents),
            'addition_amount' => Money::fromCents($additionCents),
            'total_amount' => Money::fromCents($totalCents),
            'currency' => $currency,
            'account_id' => $data->accountId,
            'category_id' => $data->categoryId,
            'cost_center_id' => $data->costCenterId,
            'status' => TitleStatus::Open,
            'notes' => $data->notes,
            'legacy_type' => $legacyType,
            'legacy_id' => $data->legacyId,
        ];
    }

    private function findExisting(
        SourceSystem $source,
        ?string $externalId,
        ?string $idempotencyKey,
        ?string $legacyType,
        ?int $legacyId,
    ): ?FinancialTitle {
        $matches = collect();

        if ($idempotencyKey !== null) {
            $matches->push(FinancialTitle::query()
                ->where('source_system_id', $source->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()->first());
        }
        if ($externalId !== null) {
            $matches->push(FinancialTitle::query()
                ->where('source_system_id', $source->id)
                ->where('external_id', $externalId)
                ->lockForUpdate()->first());
        }
        if ($legacyType !== null && $legacyId !== null) {
            $matches->push(FinancialTitle::query()
                ->where('legacy_type', $legacyType)
                ->where('legacy_id', $legacyId)
                ->lockForUpdate()->first());
        }

        $matches = $matches->filter()->unique('id')->values();
        if ($matches->count() > 1) {
            throw new DomainException('As chaves recebidas apontam para títulos diferentes.');
        }

        return $matches->first();
    }

    /**
     * Campos cuja alteração o título já liquidado não aceita.
     *
     * A regra existe para proteger DINHEIRO e DATA: valor, moeda, vencimento,
     * emissão, conta e número de parcelas de um título cujo pagamento já foi
     * registrado. Mudar qualquer um deles depois da baixa faria o extrato do
     * período deixar de bater com o que o banco já pagou.
     *
     * Documento, nome da parte, categoria e observação não são dinheiro: são
     * rótulo. O financeiro corrige esses campos na origem depois de pagar —
     * conserta um número de nota, troca o fornecedor digitado errado, completa
     * uma observação — e recusar isso deixava o Gestão exibindo para sempre um
     * dado que a origem já corrigiu, além de manter a sincronização em ERRO.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string> nomes, em português, dos campos financeiros alterados
     */
    private function camposFinanceirosAlterados(
        FinancialTitle $existing,
        array $attributes,
        int $installmentCount,
    ): array {
        $protegidos = [
            'type' => 'o tipo',
            'issue_date' => 'a emissão',
            'due_date' => 'o vencimento',
            'original_amount' => 'o valor',
            'discount_amount' => 'o desconto',
            'addition_amount' => 'o acréscimo',
            'total_amount' => 'o total',
            'currency' => 'a moeda',
            'account_id' => 'a conta',
        ];

        $alterados = [];

        foreach ($protegidos as $coluna => $rotulo) {
            if ($this->mesmoValor($existing->getAttribute($coluna), $attributes[$coluna] ?? null)) {
                continue;
            }

            $alterados[] = $rotulo;
        }

        if ($existing->installments()->count() !== $installmentCount) {
            $alterados[] = 'o número de parcelas';
        }

        return $alterados;
    }

    /**
     * Compara valor gravado com valor recebido sem tropeçar em representação:
     * o banco devolve '1738.76' e Money devolve o mesmo número por outro
     * caminho; data vem como Carbon de um lado e string do outro.
     */
    private function mesmoValor(mixed $gravado, mixed $recebido): bool
    {
        if ($gravado instanceof BackedEnum || $recebido instanceof BackedEnum) {
            $gravado = $gravado instanceof BackedEnum ? $gravado->value : $gravado;
            $recebido = $recebido instanceof BackedEnum ? $recebido->value : $recebido;
        }

        if ($gravado instanceof DateTimeInterface) {
            $gravado = $gravado->format('Y-m-d');
        }

        if ($recebido instanceof DateTimeInterface) {
            $recebido = $recebido->format('Y-m-d');
        }

        if (is_numeric($gravado) && is_numeric($recebido)) {
            return Money::toCents((string) $gravado) === Money::toCents((string) $recebido);
        }

        return (string) $gravado === (string) $recebido;
    }

    /**
     * Aplica só o que é rótulo, preservando situação, parcelas e baixas.
     *
     * Deliberadamente não chama syncInstallments: as parcelas de um título
     * liquidado espelham dinheiro que já se moveu, e nada aqui muda dinheiro.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function atualizarDescricao(
        FinancialTitle $existing,
        array $attributes,
        SourceSystem $source,
        ?int $actorId,
        ?string $correlationId,
        ?int $integrationClientId,
    ): IngestionResult {
        $before = $existing->attributesToArray();

        $existing->update([
            'payload_hash' => $attributes['payload_hash'],
            'party_type' => $attributes['party_type'],
            'party_id' => $attributes['party_id'],
            'party_name' => $attributes['party_name'],
            'document_number' => $attributes['document_number'],
            'notes' => $attributes['notes'],
        ]);

        $this->audit->record(
            'FINANCIAL_TITLE_DESCRIPTION_UPDATED',
            FinancialTitle::class,
            $existing->id,
            $before,
            $existing->fresh()->attributesToArray(),
            $source->id,
            $actorId,
            $correlationId,
            $integrationClientId,
        );

        return new IngestionResult(IngestionDecision::Updated, $existing->fresh('installments'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function payloadHash(array $attributes, int $installmentCount): string
    {
        // Idempotency-Key identifica a requisição, não o conteúdo financeiro.
        // Assim, o mesmo evento reenviado com outra chave continua idêntico.
        unset($attributes['payload_hash'], $attributes['status'], $attributes['idempotency_key']);
        $attributes['type'] = $attributes['type']->value;
        $attributes['installment_count'] = $installmentCount;

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function syncInstallments(FinancialTitle $title, int $count): void
    {
        $schedule = $this->installments->generate($title->total_amount, $count, $title->due_date->toDateString());
        $numbers = [];

        foreach ($schedule as $installment) {
            $numbers[] = $installment['installment_number'];
            $title->installments()->updateOrCreate(
                ['installment_number' => $installment['installment_number']],
                $installment,
            );
        }

        $title->installments()->whereNotIn('installment_number', $numbers)->delete();
    }

    private function nullableString(?string $value, int $maxLength, string $field): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = trim($value);
        if (mb_strlen($normalized) > $maxLength) {
            throw new DomainException("{$field} excede {$maxLength} caracteres.");
        }

        return $normalized;
    }
}
