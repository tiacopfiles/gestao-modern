<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_closures', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_session_id');
            // Cópia denormalizada de reconciliation_sessions.account_id/period_* no momento
            // do fechamento: mesmo padrão de "conta é legada, sem FK" já usado na sessão.
            $table->unsignedInteger('account_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('sequence_number');
            $table->string('status', 16)->default('CLOSED');
            $table->string('schema_version', 20);
            $table->string('engine_version', 40);
            $table->char('closure_hash', 64);
            $table->string('hash_algorithm', 20)->default('sha256');
            $table->longText('snapshot_payload');
            $table->foreignId('previous_closure_id')->nullable();
            $table->unsignedBigInteger('closed_by');
            $table->timestamp('closed_at');
            $table->unsignedBigInteger('reopened_by')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->foreign('reconciliation_session_id', 'recon_closures_session_fk')
                ->references('id')->on('reconciliation_sessions')->restrictOnDelete();
            $table->foreign('previous_closure_id', 'recon_closures_previous_fk')
                ->references('id')->on('reconciliation_closures')->restrictOnDelete();

            $table->unique(['reconciliation_session_id', 'sequence_number'], 'recon_closures_session_seq_uq');
            $table->index(['account_id', 'period_start', 'period_end'], 'recon_closures_account_period_idx');
            $table->index(['reconciliation_session_id', 'status'], 'recon_closures_session_status_idx');
            $table->index('correlation_id', 'recon_closures_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_closures');
    }
};
