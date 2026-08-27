<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationClient extends Model
{
    protected $fillable = [
        'source_system_id', 'name', 'token_prefix', 'token_hash', 'scopes', 'active',
        'expires_at', 'last_used_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'active' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    public function sourceSystem(): BelongsTo
    {
        return $this->belongsTo(SourceSystem::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(IntegrationRequest::class);
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(TitleCancellation::class);
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    public function isUsable(): bool
    {
        return $this->active
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && (bool) $this->sourceSystem?->active;
    }
}
