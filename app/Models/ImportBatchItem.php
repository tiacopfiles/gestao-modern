<?php

namespace App\Models;

use App\Domain\Banking\Enums\ImportItemResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportBatchItem extends Model
{
    protected $fillable = [
        'import_batch_id', 'position', 'external_id', 'bank_transaction_id', 'result',
        'error_code', 'error_message', 'raw_hash', 'metadata',
    ];

    protected $hidden = ['raw_hash'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'result' => ImportItemResult::class,
            'metadata' => 'array',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
