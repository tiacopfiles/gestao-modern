<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_clients', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('source_system_id');
            $table->string('name', 120);
            $table->string('token_prefix', 16);
            $table->char('token_hash', 64)->unique();
            $table->longText('scopes');
            $table->boolean('active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->foreign('source_system_id', 'integration_clients_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->index(['source_system_id', 'active'], 'integration_clients_source_active_idx');
            $table->index('token_prefix', 'integration_clients_token_prefix_idx');
            $table->index('expires_at', 'integration_clients_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_clients');
    }
};
