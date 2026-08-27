<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedInteger('actor_id')->nullable();
            $table->string('action', 80);
            $table->string('entity_type', 100);
            $table->string('entity_id', 64);
            $table->longText('before_state')->nullable();
            $table->longText('after_state')->nullable();
            $table->unsignedBigInteger('source_system_id')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('source_system_id', 'audit_events_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->index(['entity_type', 'entity_id'], 'audit_events_entity_idx');
            $table->index(['actor_id', 'occurred_at'], 'audit_events_actor_date_idx');
            $table->index('correlation_id', 'audit_events_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
