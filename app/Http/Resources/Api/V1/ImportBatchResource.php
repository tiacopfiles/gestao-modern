<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'channel' => $this->channel,
            'format' => $this->format,
            'original_filename' => $this->original_filename,
            'status' => $this->status->value,
            'total_items' => $this->total_items,
            'imported_items' => $this->imported_items,
            'duplicate_items' => $this->duplicate_items,
            'rejected_items' => $this->rejected_items,
            'period_start' => $this->period_start?->toDateString(),
            'period_end' => $this->period_end?->toDateString(),
            'correlation_id' => $this->correlation_id,
            'started_at' => $this->started_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failure' => $this->failure_code ? [
                'code' => $this->failure_code,
                'summary' => $this->failure_summary,
            ] : null,
        ];
    }
}
