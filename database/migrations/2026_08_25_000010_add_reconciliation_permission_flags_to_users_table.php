<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Até aqui, quem tinha acesso à conciliação era decidido por uma lista de ids
 * escondida no `.env` do servidor (`RECONCILIATION_*_USER_IDS`) — criar um
 * usuário na tela não dava nenhum acesso à conciliação; alguém tinha que
 * entrar no servidor e editar o arquivo à mão. Esta coluna move a concessão
 * para a tela de usuário (checkbox por permissão).
 *
 * As listas do `.env` continuam funcionando (Gate faz OR entre coluna e
 * config) — ver AppServiceProvider::boot(). Isso evita quebrar o mecanismo
 * existente e serve de via de emergência caso a tela de usuários fique fora
 * do ar. O backfill abaixo copia quem já estava liberado por config para a
 * coluna, então ninguém perde acesso quando esta migration roda em produção.
 */
return new class extends Migration
{
    private const FLAGS = [
        'reconciliation_view' => 'reconciliation.view_user_ids',
        'reconciliation_manage' => 'reconciliation.manage_user_ids',
        'reconciliation_close' => 'reconciliation.close_user_ids',
        'reconciliation_reopen' => 'reconciliation.reopen_user_ids',
        'reconciliation_export' => 'reconciliation.export_user_ids',
        'reconciliation_admin' => 'reconciliation.admin_user_ids',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        self::withRelaxedSqlMode(function (): void {
            Schema::table('users', function (Blueprint $table): void {
                foreach (array_keys(self::FLAGS) as $column) {
                    if (! SchemaCompat::hasColumn('users', $column)) {
                        $table->boolean($column)->default(false);
                    }
                }
            });
        });

        foreach (self::FLAGS as $column => $configKey) {
            $ids = config($configKey, []);
            if (! empty($ids)) {
                DB::table('users')->whereIn('id', $ids)->update([$column => true]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        self::withRelaxedSqlMode(function (): void {
            Schema::table('users', function (Blueprint $table): void {
                foreach (array_keys(self::FLAGS) as $column) {
                    if (SchemaCompat::hasColumn('users', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        });
    }

    /*
     * `avt_users` veio de um `mysqldump --no-data` de um sistema legado (ver
     * project_acop_overview) com `created_at`/`updated_at` TIMESTAMP NOT NULL
     * DEFAULT '0000-00-00 00:00:00' — um default válido no MySQL antigo, mas
     * que o sql_mode atual (NO_ZERO_DATE, herdado da conexão do Laravel)
     * proíbe. Nenhuma linha real tem data zerada; o problema é só a definição
     * da coluna. MariaDB 10.1 não tem ADD/DROP COLUMN instantâneo — todo ALTER
     * aqui reconstrói a tabela inteira, e é nessa reconstrução que o MariaDB
     * tenta recriar esse default e recusa. Relaxa o sql_mode só durante o
     * ALTER (sessão da conexão atual, não o servidor) e restaura em seguida.
     */
    private static function withRelaxedSqlMode(callable $callback): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            $callback();

            return;
        }

        $originalSqlMode = DB::selectOne('SELECT @@SESSION.sql_mode AS mode')->mode;
        DB::statement("SET SESSION sql_mode = ''");

        try {
            $callback();
        } finally {
            DB::statement("SET SESSION sql_mode = '{$originalSqlMode}'");
        }
    }
};
