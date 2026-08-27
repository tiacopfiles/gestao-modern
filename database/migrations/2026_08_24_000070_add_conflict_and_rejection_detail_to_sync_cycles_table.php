<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * O ciclo passa a distinguir quatro desfechos em vez de dois.
 *
 * Antes existia `error_count`, e qualquer coisa que caísse no catch virava
 * erro técnico: o ciclo terminava ERROR, o comando retornava FAILURE e a tarefa
 * agendada do Windows registrava resultado 1 — indefinidamente, porque a origem
 * reenvia o mesmo conflito a cada leitura.
 *
 *   - sucesso            → aplicado
 *   - rejeição esperada  → `source_rows_rejected` (a linha da origem nem é um
 *                          título válido: sem situação, sem vencimento,
 *                          cancelada, valor zero). O extrator SEMPRE soube o
 *                          motivo; o serviço é que o jogava fora. Agora ele é
 *                          agregado em `rejected_summary`
 *   - conflito/quarentena→ `conflict_count` + `origin_sync_conflicts`. Regra de
 *                          negócio recusou a mudança; não é falha do sistema
 *   - erro técnico real  → `error_count`. Só este derruba o ciclo
 *
 * `status` ganha o valor CONFLICT: RUNNING | OK | CONFLICT | ERROR. A coluna já
 * é string(20), então nenhum ALTER de tipo é necessário — o que importa aqui é
 * que ninguém interprete CONFLICT como sucesso silencioso nem como falha.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sync_cycles')) {
            return;
        }

        if (! SchemaCompat::hasColumn('sync_cycles', 'conflict_count')) {
            Schema::table('sync_cycles', function (Blueprint $table): void {
                $table->unsignedInteger('conflict_count')->default(0)->after('error_count');
            });
        }

        if (! SchemaCompat::hasColumn('sync_cycles', 'rejected_summary')) {
            Schema::table('sync_cycles', function (Blueprint $table): void {
                $table->text('rejected_summary')->nullable()->after('error_summary');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sync_cycles')) {
            return;
        }

        if (SchemaCompat::hasColumn('sync_cycles', 'conflict_count')) {
            Schema::table('sync_cycles', function (Blueprint $table): void {
                $table->dropColumn('conflict_count');
            });
        }

        if (SchemaCompat::hasColumn('sync_cycles', 'rejected_summary')) {
            Schema::table('sync_cycles', function (Blueprint $table): void {
                $table->dropColumn('rejected_summary');
            });
        }
    }
};
