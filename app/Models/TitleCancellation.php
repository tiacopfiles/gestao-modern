<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TitleCancellation extends Model
{
    protected $fillable = [
        'financial_title_id', 'integration_client_id', 'source_system_id', 'reason',
        'correlation_id', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return ['cancelled_at' => 'immutable_datetime'];
    }

    public function financialTitle(): BelongsTo
    {
        return $this->belongsTo(FinancialTitle::class);
    }

    public function integrationClient(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class);
    }

    public function sourceSystem(): BelongsTo
    {
        return $this->belongsTo(SourceSystem::class);
    }
}
