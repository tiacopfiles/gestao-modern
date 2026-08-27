<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BankTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'import_batch_id' => $this->import_batch_id,
            'external_id' => $this->external_id,
            'identity_quality' => $this->identity_quality,
            'direction' => $this->direction->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'transaction_date' => $this->transaction_date->toDateString(),
            'posted_at' => $this->posted_at?->toIso8601String(),
            'description' => $this->description_original,
            'document_number' => $this->document_number,
            'bank_reference' => $this->bank_reference,
            'end_to_end_id' => $this->end_to_end_id,
            'counterparty' => [
                'name' => $this->counterparty_name,
                'document' => $this->counterparty_document,
            ],
            'balance_after' => $this->balance_after,
        ];
    }
}
