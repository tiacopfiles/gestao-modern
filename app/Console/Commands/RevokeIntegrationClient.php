<?php

namespace App\Console\Commands;

use App\Application\Integration\IntegrationCredentialService;
use Illuminate\Console\Command;

class RevokeIntegrationClient extends Command
{
    protected $signature = 'integration-client:revoke {client_id : ID da credencial}';

    protected $description = 'Revoga uma credencial da API financeira';

    public function handle(IntegrationCredentialService $credentials): int
    {
        if (! $credentials->revoke((int) $this->argument('client_id'))) {
            $this->error('Credencial ativa não encontrada.');

            return self::FAILURE;
        }

        $this->info('Credencial revogada.');

        return self::SUCCESS;
    }
}
