<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Ciclos de sincronização com as origens legadas.
 *
 * A origem é um sistema vivo: as funcionárias criam e dão baixa em lançamentos
 * enquanto o Gestão lê. Sem registrar o ciclo, é impossível distinguir uma
 * divergência real de uma mudança legítima ocorrida entre duas leituras — que
 * foi exatamente o que aconteceu na primeira importação no servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cycles', function (Blueprint $table): void {
            $table->id();

            $table->string('source_code', 40);              // LEGACY_PAYABLE | LEGACY_RECEIVABLE
            $table->string('trigger', 20);                  // manual | scheduled | cli
            $table->unsignedBigInteger('actor_id')->nullable();

            $table->string('period_from', 10);
            $table->string('period_to', 10);

            // datetime, NÃO timestamp: com explicit_defaults_for_timestamp=0
            // (padrão do MariaDB 10.1, que é o servidor real), a primeira coluna
            // TIMESTAMP ganha DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            // e é reescrita a cada save — o início do ciclo viraria a hora do fim.
            // TIMESTAMP ainda converteria por fuso, misturando a hora do PHP com
            // a do banco. Estes são instantes registrados, não devem sofrer nada disso.
            $table->dateTime('started_at');
            $table->dateTime('source_read_completed_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            // Snapshot do que a origem tinha NO MOMENTO da leitura deste ciclo.
            // É contra isto que a fidelidade deve ser conferida, nunca contra
            // uma releitura posterior.
            $table->unsignedInteger('source_rows_read')->default(0);
            $table->unsignedInteger('source_rows_mappable')->default(0);
            $table->unsignedInteger('source_rows_rejected')->default(0);
            $table->bigInteger('source_total_cents')->default(0);
            $table->bigInteger('source_open_cents')->default(0);
            $table->bigInteger('source_settled_cents')->default(0);

            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('settlements_created')->default(0);
            $table->unsignedInteger('error_count')->default(0);

            $table->string('status', 20)->default('RUNNING'); // RUNNING | OK | ERROR
            $table->text('error_summary')->nullable();

            $table->timestamps();

            $table->index(['source_code', 'started_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cycles');
    }
};
