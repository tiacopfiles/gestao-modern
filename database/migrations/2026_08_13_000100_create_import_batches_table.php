<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('source_system_id');
            $table->unsignedBigInteger('integration_client_id')->nullable();
            // Conta é um cadastro legado sem integridade referencial comprovada.
            // A existência é validada pela aplicação; não há FK intencionalmente.
            $table->unsignedInteger('account_id');
            $table->string('channel', 20);
            $table->string('format', 30);
            $table->string('original_filename', 191)->nullable();
            $table->char('file_hash', 64)->nullable();
            $table->string('status', 20)->default('RECEIVED');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('imported_items')->default(0);
            $table->unsignedInteger('duplicate_items')->default(0);
            $table->unsignedInteger('rejected_items')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('correlation_id', 64);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->text('failure_summary')->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamps();

            $table->foreign('source_system_id', 'import_batches_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->foreign('integration_client_id', 'import_batches_client_fk')
                ->references('id')->on('integration_clients')->restrictOnDelete();
            $table->index(['source_system_id', 'account_id', 'file_hash'], 'import_batches_source_account_file_idx');
            $table->index(['source_system_id', 'status', 'created_at'], 'import_batches_source_status_date_idx');
            $table->index(['account_id', 'created_at'], 'import_batches_account_date_idx');
            $table->index('correlation_id', 'import_batches_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
