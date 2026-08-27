<?php

namespace App\Providers;

use App\Contracts\AuditEventRecorder;
use App\Contracts\BankStatementImporter;
use App\Infrastructure\Audit\DatabaseAuditEventRecorder;
use App\Infrastructure\Banking\OfxBankStatementImporter;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuditEventRecorder::class, DatabaseAuditEventRecorder::class);
        $this->app->bind(BankStatementImporter::class, OfxBankStatementImporter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // O banco legado nunca ativou esses sinalizadores. Enquanto nenhum perfil
        // estiver configurado, mantém compatibilidade; após a primeira atribuição,
        // as permissões passam a ser efetivamente aplicadas.
        Gate::define('payments', fn (User $user): bool => ! User::query()->where('pagamentos', '1')->exists() || (bool) $user->pagamentos);
        Gate::define('commercial', fn (User $user): bool => ! User::query()->where('comercial', '1')->exists() || (bool) $user->comercial);
        // Cada gate concede acesso se a checkbox do usuário estiver marcada
        // OU se o id ainda estiver nas listas do .env (RECONCILIATION_*_USER_IDS).
        // A checkbox é o caminho normal (tela de usuários); o .env fica como
        // via de emergência, sem exigir alteração de código.
        Gate::define('reconciliation:view', function (User $user): bool {
            $allowed = array_merge(
                config('reconciliation.view_user_ids', []),
                config('reconciliation.manage_user_ids', []),
            );

            return $user->reconciliation_view
                || $user->reconciliation_manage
                || in_array((int) $user->getKey(), $allowed, true);
        });
        Gate::define(
            'reconciliation:manage',
            fn (User $user): bool => $user->reconciliation_manage
                || in_array((int) $user->getKey(), config('reconciliation.manage_user_ids', []), true),
        );
        Gate::define(
            'reconciliation:close',
            fn (User $user): bool => $user->reconciliation_close
                || in_array((int) $user->getKey(), config('reconciliation.close_user_ids', []), true),
        );
        Gate::define(
            'reconciliation:reopen',
            fn (User $user): bool => $user->reconciliation_reopen
                || in_array((int) $user->getKey(), config('reconciliation.reopen_user_ids', []), true),
        );
        Gate::define('reconciliation:export', function (User $user): bool {
            $allowed = array_merge(
                config('reconciliation.export_user_ids', []),
                config('reconciliation.close_user_ids', []),
            );

            return $user->reconciliation_export
                || $user->reconciliation_close
                || in_array((int) $user->getKey(), $allowed, true);
        });
        Gate::define(
            'reconciliation:admin',
            fn (User $user): bool => $user->reconciliation_admin
                || in_array((int) $user->getKey(), config('reconciliation.admin_user_ids', []), true),
        );
    }
}
