<?php

namespace App\Console\Commands;

use App\Application\Integration\OriginSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Sincronização das origens legadas pela linha de comando.
 *
 * É este comando que a tarefa agendada do Windows executa. O lock impede que
 * duas execuções se sobreponham quando um ciclo demora mais que o intervalo
 * do agendador — sem ele, uma origem lenta produziria execuções empilhadas.
 */
class SyncOrigins extends Command
{
    protected $signature = 'gestao:sync
        {--from= : Início do período de vencimento (padrão: 1º de janeiro do ano corrente)}
        {--to= : Fim do período (padrão: 31 de dezembro do ano corrente)}
        {--trigger=scheduled : Origem do disparo (scheduled|cli|manual)}';

    protected $description = 'Sincroniza títulos das origens legadas (somente leitura) para o banco do Gestão';

    public function handle(OriginSyncService $sync): int
    {
        $ano = now()->year;
        $from = (string) ($this->option('from') ?: "{$ano}-01-01");
        $to = (string) ($this->option('to') ?: "{$ano}-12-31");

        $lock = Cache::lock('gestao:sync', 3600);

        if (! $lock->get()) {
            $this->warn('Já existe uma sincronização em andamento. Nada foi feito.');

            return self::SUCCESS;
        }

        try {
            $this->info("Sincronizando {$from} a {$to}...");

            $cycles = $sync->sync($from, $to, (string) $this->option('trigger'));

            foreach ($cycles as $cycle) {
                $this->line('');
                $this->line($cycle->label().'  ['.$cycle->status.']');
                $this->line(sprintf(
                    '  lidos=%d  novos=%d  atualizados=%d  sem mudanca=%d  baixas=%d  rejeitados=%d  conflitos=%d  erros=%d  (%ss)',
                    $cycle->source_rows_read,
                    $cycle->created_count,
                    $cycle->updated_count,
                    $cycle->unchanged_count,
                    $cycle->settlements_created,
                    $cycle->source_rows_rejected,
                    $cycle->conflict_count,
                    $cycle->error_count,
                    $cycle->durationSeconds() ?? '?',
                ));

                // Rejeição é linha da origem que nem chega a ser título válido.
                // Mostrar o motivo agregado é o que transforma "5 rejeitados"
                // de número misterioso em informação acionável.
                foreach ($cycle->rejectionReasons() as $motivo => $quantos) {
                    $this->line(sprintf('  rejeitado por %s: %d', $motivo, $quantos));
                }

                if ($cycle->hasConflicts()) {
                    $this->warn(sprintf(
                        '  %d titulo(s) em quarentena: a origem tentou alterar campo protegido de titulo '
                        .'liquidado/cancelado. O restante foi aplicado normalmente.',
                        $cycle->conflict_count,
                    ));
                }

                if ($cycle->error_summary !== null) {
                    $this->error('  '.$cycle->error_summary);
                }
            }

            // Só falha técnica devolve erro para o agendador. Um conflito de
            // regra conhecido não pode condenar a tarefa a resultado 1 eterno:
            // um alarme que toca sempre é um alarme que ninguém escuta — e o
            // dia em que a falha for real, ninguém vai olhar.
            $comFalha = collect($cycles)->contains(fn ($c) => $c->isFailure());

            return $comFalha ? self::FAILURE : self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
