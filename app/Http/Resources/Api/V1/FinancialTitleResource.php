<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FinancialTitleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'external_id' => $this->external_id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'document_number' => $this->document_number,
            'issue_date' => $this->issue_date->toDateString(),
            'due_date' => $this->due_date->toDateString(),
            'original_amount' => $this->original_amount,
            'discount_amount' => $this->discount_amount,
            'addition_amount' => $this->addition_amount,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'party' => [
                'id' => $this->party_id,
                'type' => $this->party_type,
                'name' => $this->party_name,
            ],
            'account_id' => $this->account_id,
            'category_id' => $this->category_id,
            'cost_center_id' => $this->cost_center_id,
            'installment_count' => $this->whenLoaded('installments', fn (): int => $this->installments->count()),
            'installments' => $this->whenLoaded('installments', fn () => $this->installments->map(fn ($installment): array => [
                'number' => $installment->installment_number,
                'due_date' => $installment->due_date->toDateString(),
                'amount' => $installment->amount,
                'status' => $installment->status->value,
            ])->values()),
            'notes' => $this->notes,
            'cancellation' => $this->when(
                $this->relationLoaded('cancellation') && $this->cancellation !== null,
                fn (): array => [
                    'reason' => $this->cancellation->reason,
                    'cancelled_at' => $this->cancellation->cancelled_at->toIso8601String(),
                ],
            ),
        ];
    }
}
