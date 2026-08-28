<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * `period_statement_lines.manual_position` — a posição que uma pessoa
 * escolheu à mão, arrastando a linha, dentro do MESMO dia (`movement_date`).
 * Separada de `line_number` (que continua sendo a posição final resolvida, e
 * é recalculada a cada `refresh()`/reordenação) para dar um sinal limpo do
 * que foi fixado por alguém e do que é só a ordem cronológica padrão.
 *
 * Nula na maioria das linhas — só existe quando alguém reordenou aquele dia.
 * Sobrevive ao rebuild de `refresh()` porque é recuperada pela identidade
 * real da linha (`title_settlement_id`/`manual_movement_id`, a mesma chave
 * que já decide o que é novo/mudou/saiu) antes de a linha antiga ser
 * apagada, e reanexada na nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('period_statement_lines') && ! SchemaCompat::hasColumn('period_statement_lines', 'manual_position')) {
            Schema::table('period_statement_lines', function (Blueprint $table): void {
                $table->unsignedSmallInteger('manual_position')->nullable()->after('line_number');
                $table->index(['period_statement_id', 'movement_date'], 'period_statement_lines_day_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('period_statement_lines') && SchemaCompat::hasColumn('period_statement_lines', 'manual_position')) {
            Schema::table('period_statement_lines', function (Blueprint $table): void {
                $table->dropIndex('period_statement_lines_day_idx');
                $table->dropColumn('manual_position');
            });
        }
    }
};
