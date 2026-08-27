<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Quarentena de conflitos da sincronização com as origens legadas.
 *
 * O caso real que motivou a tabela: a origem alterou a data de emissão de um
 * título que o Gestão já tem liquidado. A regra de negócio recusa sobrescrever
 * campo financeiro de título liquidado — e está certa. Mas como a origem
 * continua reenviando o mesmo dado a cada leitura, o ciclo terminava em ERROR
 * a cada 5 minutos, para sempre, por um conflito conhecido e esperado.
 *
 * Um conflito de REGRA não é uma falha técnica. Ele precisa ficar registrado,
 * nomeado e visível para alguém decidir — não fazer a tarefa agendada gritar
 * eternamente, o que treina a operação a ignorar o alarme (e a ignorar junto o
 * dia em que a falha for de verdade).
 *
 * A identidade é `(source_code, external_id)`: um título conflitante é UMA
 * linha que se repete, com contador e primeira/última ocorrência, e não um
 * registro novo a cada ciclo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('origin_sync_conflicts')) {
            return;
        }

        Schema::create('origin_sync_conflicts', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();

            $table->string('source_code', 40);              // LEGACY_PAYABLE | LEGACY_RECEIVABLE
            $table->string('external_id', 128);             // id do lançamento na origem
            $table->unsignedBigInteger('financial_title_id')->nullable();

            // A classe da exceção que o domínio levantou, normalizada: é ela
            // que diz QUE tipo de conflito é, independente do texto da mensagem.
            $table->string('kind', 60);
            $table->string('reason', 250);

            // Instantes registrados, não timestamps: ver a nota da migration
            // dos ciclos sobre explicit_defaults_for_timestamp no MariaDB 10.1.
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->unsignedInteger('occurrences')->default(1);
            $table->unsignedBigInteger('last_sync_cycle_id')->nullable();

            // Resolver é uma decisão humana: ou a origem foi corrigida, ou
            // alguém aceitou a divergência. Enquanto for nulo, o conflito está
            // aberto e aparece na fila.
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution_note', 250)->nullable();

            $table->timestamps();

            $table->unique(['source_code', 'external_id'], 'origin_sync_conflicts_identity_uq');
            $table->index(['resolved_at', 'last_seen_at'], 'origin_sync_conflicts_open_idx');
            $table->index('financial_title_id', 'origin_sync_conflicts_title_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('origin_sync_conflicts');
    }
};
