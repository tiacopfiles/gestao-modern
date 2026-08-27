<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'external_id' => $this->external_id,
            'bank_transaction_id' => $this->bank_transaction_id,
            'result' => $this->result->value,
            'error' => $this->error_code ? [
                'code' => $this->error_code,
                'message' => $this->error_message,
            ] : null,
        ];
    }
}
