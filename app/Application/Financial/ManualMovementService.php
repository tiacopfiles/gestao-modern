<?php

namespace App\Application\Financial;

use App\Contracts\AuditEventRecorder;
use App\Domain\Financial\Enums\ManualMovementDirection;
use App\Domain\Financial\Enums\PeriodStatementStatus;
use App\Domain\Financial\Money;
use App\Models\ManualMovement;
use App\Models\PeriodStatement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registro de entradas e saídas lançadas à mão.
 *
 * Toda operação é auditada com estado anterior e posterior, e nenhuma delas
 * toca título, liquidação, status ou sistema de origem — são fluxos separados.
 */
class ManualMovementService
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    /**
     * @param  array{account_id: int, movement_date: string, direction: string, amount: string, history: string, category_id?: int|null, notes?: string|null}  $dados
     */
    public function create(array $dados, ?int $actorId = null): ManualMovement
    {
        $this->validarValor($dados['amount']);

        return DB::transaction(function () use ($dados, $actorId): ManualMovement {
            $correlationId = (string) Str::uuid();

            $movimento = ManualMovement::create([
                'account_id' => $dados['account_id'],
                'bank_account_id' => $dados['bank_account_id'] ?? null,
                'document_number' => $dados['document_number'] ?? null,
                'movement_date' => $dados['movement_date'],
                'direction' => $dados['direction'],
                'amount' => $dados['amount'],
                'history' => $dados['history'],
                'category_id' => $dados['category_id'] ?? null,
                'notes' => $dados['notes'] ?? null,
                'created_by' => $actorId,
                'updated_by' => $actorId,
                'correlation_id' => $correlationId,
                // Só a importação de planilha preenche. O índice UNIQUE é o que
                // impede a mesma linha de entrar duas vezes.
                'import_key' => $dados['import_key'] ?? null,
            ]);

            $this->audit->record(
                'MANUAL_MOVEMENT_CREATED',
                ManualMovement::class,
                $movimento->id,
                null,
                $this->estado($movimento->fresh()),
                null,
                $actorId,
                $correlationId,
            );

            return $movimento->fresh();
        });
    }

    /**
     * @param  array{account_id: int, movement_date: string, direction: string, amount: string, history: string, category_id?: int|null, notes?: string|null}  $dados
     */
    public function update(ManualMovement $movimento, array $dados, ?int $actorId = null): ManualMovement
    {
        $this->validarValor($dados['amount']);
        $this->recusarSeCongelado($movimento, 'alterado');

        return DB::transaction(function () use ($movimento, $dados, $actorId): ManualMovement {
            $antes = $this->estado($movimento);
            $correlationId = (string) Str::uuid();

            $movimento->update([
                'account_id' => $dados['account_id'],
                'bank_account_id' => $dados['bank_account_id'] ?? null,
                'document_number' => $dados['document_number'] ?? null,
                'movement_date' => $dados['movement_date'],
                'direction' => $dados['direction'],
                'amount' => $dados['amount'],
                'history' => $dados['history'],
                'category_id' => $dados['category_id'] ?? null,
                'notes' => $dados['notes'] ?? null,
                'updated_by' => $actorId,
            ]);

            $this->audit->record(
                'MANUAL_MOVEMENT_UPDATED',
                ManualMovement::class,
                $movimento->id,
                $antes,
                $this->estado($movimento->fresh()),
                null,
                $actorId,
                $correlationId,
            );

            return $movimento->fresh();
        });
    }

    public function delete(ManualMovement $movimento, ?int $actorId = null): void
    {
        $this->recusarSeCongelado($movimento, 'excluído');

        DB::transaction(function () use ($movimento, $actorId): void {
            $antes = $this->estado($movimento);
            $correlationId = (string) Str::uuid();

            $movimento->update(['updated_by' => $actorId]);
            $movimento->delete();

            $this->audit->record(
                'MANUAL_MOVEMENT_DELETED',
                ManualMovement::class,
                $movimento->id,
                $antes,
                null,
                null,
                $actorId,
                $correlationId,
            );
        });
    }

    /**
     * Um movimento que pertence a uma conciliação FECHADA não muda mais.
     *
     * Enquanto a conciliação está ABERTA, o objetivo é justamente permitir
     * corrigir o movimento e refletir com "Atualizar" — é para isso que o
     * ciclo aberto/fechado existe. A trava só entra quando o período já foi
     * fechado: aí sim, corrigir o movimento faria o retrato definitivo
     * discordar do lançamento que o originou, e ninguém saberia qual dos dois
     * está certo. Corrigir depois do fechamento exige reabrir/gerar um
     * período novo, não reescrever a história.
     */
    private function recusarSeCongelado(ManualMovement $movimento, string $verbo): void
    {
        $statement = PeriodStatement::query()
            ->where('account_id', $movimento->account_id)
            ->where('status', PeriodStatementStatus::Closed->value)
            ->whereDate('period_start', '<=', $movimento->movement_date->toDateString())
            ->whereDate('period_end', '>=', $movimento->movement_date->toDateString())
            ->orderBy('id')
            ->first();

        if ($statement === null) {
            return;
        }

        throw new DomainException(sprintf(
            'Este movimento não pode ser %s: ele pertence à conciliação FECHADA de %s a %s '
            .'da conta %s, fechada em %s. Uma conciliação fechada é o retrato definitivo do '
            .'período e não pode mudar.',
            $verbo,
            $statement->period_start->format('d/m/Y'),
            $statement->period_end->format('d/m/Y'),
            $statement->account_name,
            $statement->closed_at?->format('d/m/Y H:i') ?? '—',
        ));
    }

    /**
     * `Money::toCents` recusa formato inválido, e é ele que decide o que vale.
     * Validar aqui, antes de gravar, transforma um erro de digitação numa
     * mensagem em vez de uma exceção no meio da transação.
     */
    private function validarValor(string $amount): void
    {
        if (Money::toCents($amount) <= 0) {
            throw new DomainException('O valor do movimento precisa ser maior que zero.');
        }
    }

    /** @return array<string, mixed> */
    private function estado(ManualMovement $movimento): array
    {
        return [
            'account_id' => $movimento->account_id,
            'movement_date' => $movimento->movement_date->toDateString(),
            'direction' => $movimento->direction->value,
            'direction_label' => $movimento->direction->label(),
            'amount' => (string) $movimento->amount,
            'history' => $movimento->history,
            'category_id' => $movimento->category_id,
            'notes' => $movimento->notes,
        ];
    }

    /**
     * Movimentos manuais confirmados de uma conta num período, já ordenados.
     *
     * @return Collection<int, ManualMovement>
     */
    public function noPeriodo(int $accountId, string $from, string $to)
    {
        // Mesmo limite superior usado no movimento do período: "< dia seguinte".
        return ManualMovement::query()
            ->where('account_id', $accountId)
            ->where('movement_date', '>=', $from)
            ->where('movement_date', '<', CarbonImmutable::parse($to)->addDay()->toDateString())
            ->orderBy('movement_date')
            ->orderBy('id')
            ->get();
    }

    /** @return array<string, ManualMovementDirection> */
    public static function direcoes(): array
    {
        return [
            ManualMovementDirection::In->value => ManualMovementDirection::In,
            ManualMovementDirection::Out->value => ManualMovementDirection::Out,
        ];
    }
}
