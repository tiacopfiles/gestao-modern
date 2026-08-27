<?php

namespace App\Application\Integration;

use App\Models\IntegrationClient;
use App\Models\SourceSystem;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IntegrationCredentialService
{
    /**
     * @param  list<string>  $scopes
     */
    public function issue(
        string $sourceCode,
        string $name,
        array $scopes,
        ?CarbonImmutable $expiresAt = null,
    ): IssuedIntegrationCredential {
        $sourceCode = Str::upper(trim($sourceCode));
        $name = trim($name);
        $scopes = array_values(array_unique(array_map('trim', $scopes)));
        $allowedScopes = config('integrations.scopes', []);

        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('O nome da credencial deve ter entre 1 e 120 caracteres.');
        }

        if ($scopes === [] || array_diff($scopes, $allowedScopes) !== []) {
            throw new DomainException('Informe ao menos um escopo válido: '.implode(', ', $allowedScopes).'.');
        }

        if ($expiresAt?->isPast()) {
            throw new DomainException('A expiração deve estar no futuro.');
        }

        $source = SourceSystem::query()
            ->where('code', $sourceCode)
            ->where('active', true)
            ->first();

        if (! $source) {
            throw new DomainException('Sistema de origem inexistente ou inativo.');
        }

        $plainTextToken = 'acop_'.bin2hex(random_bytes(32));
        $client = DB::transaction(fn (): IntegrationClient => IntegrationClient::query()->create([
            'source_system_id' => $source->id,
            'name' => $name,
            'token_prefix' => substr($plainTextToken, 0, 12),
            'token_hash' => hash('sha256', $plainTextToken),
            'scopes' => $scopes,
            'active' => true,
            'expires_at' => $expiresAt,
        ]));

        return new IssuedIntegrationCredential($client, $plainTextToken);
    }

    public function revoke(int $clientId): bool
    {
        $client = IntegrationClient::query()->find($clientId);

        if (! $client || ! $client->active) {
            return false;
        }

        $client->update(['active' => false]);

        return true;
    }
}
