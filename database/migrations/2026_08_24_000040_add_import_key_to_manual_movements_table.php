<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identidade da linha de origem quando o movimento veio de uma importação.
 *
 * Não dá para reaproveitar `correlation_id` para isto: aquele campo é o fio da
 * AUDITORIA — o serviço gera um UUID novo a cada operação e o usa para amarrar
 * o evento ao registro. Sobrecarregá-lo com um segundo significado quebraria a
 * rastreabilidade no dia em que alguém precisasse dela.
 *
 * `import_key` é derivada do conteúdo da linha na planilha (empresa, aba, data,
 * sentido, valor, histórico e a ocorrência dentro da aba). O índice é UNIQUE de
 * propósito: a garantia de não importar duas vezes passa a ser do banco, e não
 * da disciplina de quem roda o comando. NULL fica livre para repetir, que é o
 * caso de todo movimento digitado à mão na tela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manual_movements') || SchemaCompat::hasColumn('manual_movements', 'import_key')) {
            return;
        }

        Schema::table('manual_movements', function (Blueprint $table): void {
            $table->string('import_key', 64)->nullable()->after('correlation_id');
            $table->unique('import_key', 'manual_movements_import_key_uq');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('manual_movements') || ! SchemaCompat::hasColumn('manual_movements', 'import_key')) {
            return;
        }

        Schema::table('manual_movements', function (Blueprint $table): void {
            $table->dropUnique('manual_movements_import_key_uq');
            $table->dropColumn('import_key');
        });
    }
};
