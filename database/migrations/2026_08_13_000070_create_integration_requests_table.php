<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_requests', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('integration_client_id');
            $table->unsignedBigInteger('source_system_id');
            $table->char('idempotency_key_hash', 64);
            $table->string('idempotency_key_prefix', 16);
            $table->string('request_method', 10);
            $table->string('request_path', 191);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('PROCESSING');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('correlation_id', 64);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('integration_client_id', 'integration_requests_client_fk')
                ->references('id')->on('integration_clients')->restrictOnDelete();
            $table->foreign('source_system_id', 'integration_requests_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->unique(
                ['integration_client_id', 'idempotency_key_hash'],
                'integration_requests_client_key_uq',
            );
            $table->index(
                ['source_system_id', 'status', 'created_at'],
                'integration_requests_source_status_date_idx',
            );
            $table->index(['status', 'updated_at'], 'integration_requests_status_updated_idx');
            $table->index('correlation_id', 'integration_requests_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_requests');
    }
};
