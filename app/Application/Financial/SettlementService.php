<?php

namespace App\Application\Financial;

use App\Contracts\AuditEventRecorder;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Enums\SettlementStatus;
use App\Domain\Financial\Enums\SettlementType;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Money;
use App\Models\BankAccount;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use App\Models\TitleInstallment;
use App\Models\TitleSettlement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettlementService
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    /**
     * Registra a realização financeira de um título.
     *
     * `$bankAccountId` é a conta por onde o dinheiro passou de verdade.
     *
     * A sincronização com as origens não informa banco — `contas` e
     * `contasareceber` não guardam essa coluna. Quando vem nulo, o serviço
     * **deduz**: se a empresa do título tem uma única conta bancária ativa, é
     * por ela que o dinheiro passou, porque não existe outra. Se tiver duas ou
     * mais, continua nulo — aí a informação falta de verdade e vira pendência
     * visível, para alguém que saiba atribuir depois.
     *
     * Isto não reabre a convenção que o ADR-017 encerrou: aquela elegia uma
     * conta entre várias pelo flag `is_default`, e custou −R$ 1.805.279,37 em
     * 2026. Deduzir de um cadastro com um item só não escolhe nada. Ver
     * ADR-018.
     */
    public function settle(
        int $titleId,
        int|string $amount,
        string $settlementDate,
        ?int $installmentId = null,
        ?int $sourceSystemId = null,
        ?string $externalId = null,
        ?string $idempotencyKey = null,
        ?int $actorId = null,
        ?int $bankAccountId = null,
    ): TitleSettlement {
        $externalId = $this->nullableString($externalId, 128, 'external_id');
        $idempotencyKey = $this->nullableString($idempotencyKey, 128, 'Idempotency-Key');

        if ($sourceSystemId === null && ($externalId !== null || $idempotencyKey !== null)) {
            throw new DomainException('Uma chave externa de liquidação exige source_system_id.');
        }

        return DB::transaction(function () use (
            $titleId, $amount, $settlementDate, $installmentId, $sourceSystemId,
            $externalId, $idempotencyKey, $actorId, $bankAccountId
        ): TitleSettlement {
            if ($sourceSystemId !== null) {
                $source = SourceSystem::query()
                    ->whereKey($sourceSystemId)
                    ->where('active', true)
                    ->lockForUpdate()
                    ->first();
                if (! $source) {
                    throw new DomainException('Sistema de origem da liquidação inexistente ou inativo.');
                }
            }

            $title = FinancialTitle::query()->lockForUpdate()->findOrFail($titleId);
            if ($title->status === TitleStatus::Cancelled) {
                throw new DomainException('Título cancelado não pode ser liquidado.');
            }

            $amountCents = Money::toCents($amount);
            if ($amountCents <= 0) {
                throw new DomainException('O valor da liquidação deve ser maior que zero.');
            }

            $bankAccountId = $this->resolverContaBancaria($title, $bankAccountId);
            $this->validarContaBancaria($title, $bankAccountId);

            $installment = $this->resolveInstallment($title, $installmentId);
            $type = $title->type === FinancialTitleType::Payable
                ? SettlementType::Payment
                : SettlementType::Receipt;
            $correlationId = (string) Str::uuid();
            $payload = [
                'title_id' => $title->id,
                'installment_id' => $installment?->id,
                'date' => CarbonImmutable::parse($settlementDate)->toDateString(),
                'amount' => Money::fromCents($amountCents),
                'type' => $type->value,
            ];
            $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

            if ($sourceSystemId !== null && ($externalId !== null || $idempotencyKey !== null)) {
                $existing = $this->findExisting($sourceSystemId, $externalId, $idempotencyKey);
                if ($existing) {
                    if ($existing->payload_hash !== $payloadHash) {
                        throw new DomainException('A chave da liquidação já foi usada com conteúdo diferente.');
                    }

                    return $existing;
                }
            }

            $availableCents = $installment ? $installment->remainingCents() : $title->remainingCents();
            if ($amountCents > $availableCents) {
                throw new DomainException('A liquidação excede o saldo disponível.');
            }

            $settlement = TitleSettlement::query()->create([
                'financial_title_id' => $title->id,
                'title_installment_id' => $installment?->id,
                'bank_account_id' => $bankAccountId,
                'settlement_date' => $payload['date'],
                'amount' => $payload['amount'],
                'type' => $type,
                'status' => SettlementStatus::Confirmed,
                'source_system_id' => $sourceSystemId,
                'external_id' => $externalId,
                'idempotency_key' => $idempotencyKey,
                'payload_hash' => $payloadHash,
                'created_by' => $actorId,
                'correlation_id' => $correlationId,
            ]);

            if ($installment) {
                $installment->update(['status' => $this->statusForRemaining($installment->remainingCents())]);
            }
            $title->update(['status' => $this->statusForRemaining($title->remainingCents())]);

            $this->audit->record(
                'TITLE_SETTLEMENT_RECORDED',
                TitleSettlement::class,
                $settlement->id,
                null,
                $settlement->fresh()->attributesToArray(),
                $sourceSystemId,
                $actorId,
                $correlationId,
            );

            return $settlement->fresh();
        }, 3);
    }

    /**
     * Preenche a conta bancária quando ela é dedutível.
     *
     * Só age sobre o nulo, e só quando a empresa do título tem exatamente uma
     * conta ativa. Conta informada explicitamente passa intacta — inclusive
     * quando está errada, porque quem valida isso é `validarContaBancaria()`.
     *
     * Título sem `account_id` fica nulo: sem empresa não há cadastro de conta
     * para consultar.
     */
    private function resolverContaBancaria(FinancialTitle $title, ?int $bankAccountId): ?int
    {
        if ($bankAccountId !== null || $title->account_id === null) {
            return $bankAccountId;
        }

        return BankAccount::contaUnicaDaEmpresa((int) $title->account_id)?->id;
    }

    /**
     * A conta bancária informada existe, está ativa e é da empresa do título?
     *
     * Nulo passa: é o "ainda não se sabe" legítimo da sincronização. O que não
     * pode passar é uma conta de OUTRA empresa — seria misturar o extrato de
     * duas pessoas jurídicas, exatamente o tipo de erro que a conciliação por
     * banco existe para impedir.
     *
     * Título sem `account_id` (empresa não resolvida na origem) não pode
     * receber conta bancária: não há como conferir a que empresa ela pertence,
     * e atribuir sem conferir é a convenção que o ADR-017 encerra.
     */
    private function validarContaBancaria(FinancialTitle $title, ?int $bankAccountId): void
    {
        if ($bankAccountId === null) {
            return;
        }

        $conta = BankAccount::query()->find($bankAccountId);

        if ($conta === null) {
            throw new DomainException('Conta bancária informada não existe.');
        }

        if (! $conta->active) {
            throw new DomainException('Conta bancária inativa não pode receber liquidação.');
        }

        if ($title->account_id === null) {
            throw new DomainException(
                'Este título ainda não tem empresa definida — corrija o cadastro na origem antes de '
                .'apontar a conta bancária.'
            );
        }

        if ((int) $conta->company_id !== (int) $title->account_id) {
            throw new DomainException('A conta bancária pertence a outra empresa.');
        }
    }

    /**
     * Define (ou corrige) a conta bancária de uma liquidação que já existe.
     *
     * É por aqui que a fila de "conta bancária pendente" é resolvida: a
     * liquidação vinda do sync nasce sem banco, e quem sabe por onde o dinheiro
     * passou completa depois. Não cria liquidação nova, não duplica título e
     * não mexe na data — mudar a data para "fazer entrar" numa conciliação
     * falsearia o fato financeiro.
     */
    public function definirContaBancaria(
        TitleSettlement $settlement,
        ?int $bankAccountId,
        ?int $actorId = null,
        ?string $motivo = null,
    ): TitleSettlement {
        return DB::transaction(function () use ($settlement, $bankAccountId, $actorId, $motivo): TitleSettlement {
            $settlement = TitleSettlement::query()->lockForUpdate()->findOrFail($settlement->getKey());
            $title = FinancialTitle::query()->findOrFail($settlement->financial_title_id);

            $this->validarContaBancaria($title, $bankAccountId);

            $anterior = $settlement->bank_account_id;

            if ((int) $anterior === (int) $bankAccountId) {
                return $settlement;
            }

            $settlement->update(['bank_account_id' => $bankAccountId]);

            $this->audit->record(
                'TITLE_SETTLEMENT_BANK_ACCOUNT_SET',
                TitleSettlement::class,
                $settlement->id,
                ['bank_account_id' => $anterior],
                [
                    'bank_account_id' => $bankAccountId,
                    'financial_title_id' => $settlement->financial_title_id,
                    'settlement_date' => $settlement->settlement_date->toDateString(),
                    'amount' => (string) $settlement->amount,
                    'reason' => $motivo,
                ],
                null,
                $actorId,
                (string) Str::uuid(),
            );

            return $settlement->fresh();
        }, 3);
    }

    private function resolveInstallment(FinancialTitle $title, ?int $installmentId): ?TitleInstallment
    {
        if ($installmentId) {
            $installment = TitleInstallment::query()->lockForUpdate()->findOrFail($installmentId);
            if ($installment->financial_title_id !== $title->id) {
                throw new DomainException('A parcela não pertence ao título informado.');
            }

            return $installment;
        }

        $installmentCount = $title->installments()->count();
        if ($installmentCount === 1) {
            return $title->installments()->lockForUpdate()->first();
        }

        if ($installmentCount > 1) {
            throw new DomainException('A parcela é obrigatória para liquidar um título parcelado.');
        }

        return null;
    }

    private function statusForRemaining(int $remainingCents): TitleStatus
    {
        return $remainingCents === 0 ? TitleStatus::Settled : TitleStatus::PartiallySettled;
    }

    private function findExisting(
        int $sourceSystemId,
        ?string $externalId,
        ?string $idempotencyKey,
    ): ?TitleSettlement {
        $matches = collect();

        if ($externalId !== null) {
            $matches->push(TitleSettlement::query()
                ->where('source_system_id', $sourceSystemId)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first());
        }
        if ($idempotencyKey !== null) {
            $matches->push(TitleSettlement::query()
                ->where('source_system_id', $sourceSystemId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first());
        }

        $matches = $matches->filter()->unique('id')->values();
        if ($matches->count() > 1) {
            throw new DomainException('As chaves recebidas apontam para liquidações diferentes.');
        }

        return $matches->first();
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
