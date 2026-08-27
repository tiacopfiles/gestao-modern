<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_sessions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            // Conta e usuários pertencem ao legado. A aplicação valida esses IDs;
            // nenhuma FK é criada contra estruturas protegidas.
            $table->unsignedInteger('account_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('OPEN');
            $table->unsignedInteger('created_by');
            $table->unsignedInteger('updated_by')->nullable();
            $table->string('correlation_id', 64);
            $table->timestamps();

            $table->unique(
                ['account_id', 'period_start', 'period_end'],
                'reconciliation_sessions_account_period_uq',
            );
            $table->index(
                ['account_id', 'status', 'period_start'],
                'reconciliation_sessions_account_status_period_idx',
            );
            $table->index('correlation_id', 'reconciliation_sessions_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_sessions');
    }
};
