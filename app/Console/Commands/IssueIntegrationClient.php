<?php

namespace App\Console\Commands;

use App\Application\Integration\IntegrationCredentialService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Throwable;

class IssueIntegrationClient extends Command
{
    protected $signature = 'integration-client:issue
        {source : Código do sistema de origem}
        {name : Nome descritivo da credencial}
        {--scope=* : Escopo autorizado; pode ser repetido}
        {--expires= : Expiração ISO-8601 opcional}';

    protected $description = 'Emite uma credencial Bearer para a API financeira';

    public function handle(IntegrationCredentialService $credentials): int
    {
        try {
            $expiresAt = $this->option('expires')
                ? CarbonImmutable::parse((string) $this->option('expires'))
                : null;
            $issued = $credentials->issue(
                (string) $this->argument('source'),
                (string) $this->argument('name'),
                $this->option('scope'),
                $expiresAt,
            );
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Não foi possível interpretar os parâmetros informados.');

            return self::FAILURE;
        }

        $this->info('Credencial criada. Copie o token agora; ele não será exibido novamente.');
        $this->line('ID: '.$issued->client->id);
        $this->line('Token: '.$issued->plainTextToken);

        return self::SUCCESS;
    }
}
