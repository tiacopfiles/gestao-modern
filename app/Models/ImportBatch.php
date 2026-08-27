<?php

namespace App\Models;

use App\Domain\Banking\Enums\ImportBatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'source_system_id', 'integration_client_id', 'account_id', 'channel', 'format',
        'original_filename', 'file_hash', 'status', 'total_items', 'imported_items',
        'duplicate_items', 'rejected_items', 'period_start', 'period_end', 'correlation_id',
        'started_at', 'completed_at', 'failure_code', 'failure_summary', 'metadata',
    ];

    protected $hidden = ['file_hash'];

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'total_items' => 'integer',
            'imported_items' => 'integer',
            'duplicate_items' => 'integer',
            'rejected_items' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function sourceSystem(): BelongsTo
    {
        return $this->belongsTo(SourceSystem::class);
    }

    public function integrationClient(): BelongsTo
    {
        return $this->belongsTo(IntegrationClient::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ImportBatchItem::class)->orderBy('position');
    }
}
