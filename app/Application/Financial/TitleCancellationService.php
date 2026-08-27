<?php

namespace App\Application\Financial;

use App\Contracts\AuditEventRecorder;
use App\Domain\Financial\CancellationResult;
use App\Domain\Financial\Enums\TitleStatus;
use App\Domain\Financial\Exceptions\TitleAlreadySettled;
use App\Domain\Financial\Exceptions\TitleCancellationNotAllowed;
use App\Models\FinancialTitle;
use App\Models\TitleCancellation;
use Illuminate\Support\Facades\DB;

class TitleCancellationService
{
    public function __construct(private readonly AuditEventRecorder $audit) {}

    public function cancel(
        int $titleId,
        int $sourceSystemId,
        int $integrationClientId,
        string $reason,
        string $correlationId,
    ): CancellationResult {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 1000) {
            throw new TitleCancellationNotAllowed('O motivo do cancelamento deve ter entre 1 e 1000 caracteres.');
        }

        return DB::transaction(function () use (
            $titleId,
            $sourceSystemId,
            $integrationClientId,
            $reason,
            $correlationId,
        ): CancellationResult {
            $title = FinancialTitle::query()->lockForUpdate()->findOrFail($titleId);

            if ($title->source_system_id !== $sourceSystemId) {
                throw new TitleCancellationNotAllowed('O título não pertence ao sistema de origem autenticado.');
            }

            if ($title->settlements()->exists()) {
                throw new TitleAlreadySettled('Título com liquidação não pode ser cancelado pela integração.');
            }

            if ($title->status === TitleStatus::Cancelled) {
                $cancellation = $title->cancellation()->first();

                if ($cancellation?->source_system_id === $sourceSystemId && $cancellation->reason === $reason) {
                    return new CancellationResult($title->load('cancellation'), true);
                }

                throw new TitleCancellationNotAllowed('O título já foi cancelado com outro motivo.');
            }

            if ($title->status !== TitleStatus::Open) {
                throw new TitleCancellationNotAllowed('Somente títulos em aberto e sem liquidações podem ser cancelados.');
            }

            $before = $title->attributesToArray();
            $cancellation = TitleCancellation::query()->create([
                'financial_title_id' => $title->id,
                'integration_client_id' => $integrationClientId,
                'source_system_id' => $sourceSystemId,
                'reason' => $reason,
                'correlation_id' => $correlationId,
                'cancelled_at' => now(),
            ]);

            $title->update(['status' => TitleStatus::Cancelled]);
            $after = $title->fresh()->attributesToArray();
            $after['cancellation'] = $cancellation->attributesToArray();

            $this->audit->record(
                'FINANCIAL_TITLE_CANCELLED',
                FinancialTitle::class,
                $title->id,
                $before,
                $after,
                $sourceSystemId,
                null,
                $correlationId,
                $integrationClientId,
            );

            return new CancellationResult($title->fresh('cancellation'), false);
        }, 3);
    }
}
