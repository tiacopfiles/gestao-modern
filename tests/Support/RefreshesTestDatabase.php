<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshesTestDatabase
{
    use RefreshDatabase {
        refreshDatabase as private refreshDatabaseWithTransactions;
    }

    /**
     * MariaDB/MySQL commits DDL implicitly. Some characterization tests create
     * synthetic legacy tables, so a transaction-based refresh is not valid there.
     * The guarded disposable database is rebuilt for each test instead.
     */
    public function refreshDatabase(): void
    {
        if (in_array(config('database.default'), ['mysql', 'mariadb'], true)) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());
            $this->app[Kernel::class]->setArtisan(null);

            return;
        }

        $this->refreshDatabaseWithTransactions();
    }
}
