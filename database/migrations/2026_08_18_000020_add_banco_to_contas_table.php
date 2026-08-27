<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * O cabeçalho do movimento do período pede o banco e o nome da conta, e o banco
 * não existia em lugar nenhum — nem aqui, nem nas origens. É preenchido uma vez
 * por conta, no cadastro.
 *
 * Esta migration só ACRESCENTA a coluna; ela não cria `contas`. A tabela é
 * herdada do sistema antigo e não pertence a este projeto: onde ela não existe,
 * quem a cria é a sincronização, já com a coluna. Criar aqui faria a migration
 * disputar a tabela com quem a define de verdade.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contas') || SchemaCompat::hasColumn('contas', 'banco')) {
            return;
        }

        Schema::table('contas', function (Blueprint $table): void {
            $table->string('banco', 120)->nullable()->after('nome');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('contas') && SchemaCompat::hasColumn('contas', 'banco')) {
            Schema::table('contas', function (Blueprint $table): void {
                $table->dropColumn('banco');
            });
        }
    }
};
