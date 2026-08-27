<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceSystem extends Model
{
    protected $fillable = ['code', 'name', 'type', 'active', 'configuration'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'configuration' => 'array',
        ];
    }

    public function financialTitles(): HasMany
    {
        return $this->hasMany(FinancialTitle::class);
    }

    public function integrationClients(): HasMany
    {
        return $this->hasMany(IntegrationClient::class);
    }

    public function bankTransactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }
}
