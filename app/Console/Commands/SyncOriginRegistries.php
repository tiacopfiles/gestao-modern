<?php

namespace App\Console\Commands;

use App\Application\Integration\OriginRegistrySyncService;
use Illuminate\Console\Command;

/**
 * Traz os cadastros das origens: categorias, tipos, situações, centros de custo,
 * fornecedores e clientes.
 *
 * Separado de `gestao:sync` de propósito. Cadastro muda raramente e são ~7 mil
 * linhas de fornecedor e cliente; misturar com a sincronização de títulos, que
 * roda a cada 5 minutos, seria varrer as duas tabelas o tempo todo à toa.
 */
class SyncOriginRegistries extends Command
{
    protected $signature = 'gestao:sync-cadastros';

    protected $description = 'Importa categorias, tipos, situações, centros de custo, fornecedores e clientes das origens legadas (somente leitura)';

    public function handle(OriginRegistrySyncService $sync): int
    {
        $this->info('Lendo os cadastros das origens (somente leitura)...');

        $resultado = $sync->sync();

        $this->line('');
        $this->table(
            ['Cadastro', 'Lidos', 'Criados', 'Já existiam'],
            collect($resultado)->map(fn (array $s, string $nome): array => [
                $nome,
                $s['lidos'],
                $s['criados'],
                $s['ignorados'],
            ])->values()->all(),
        );

        return self::SUCCESS;
    }
}
