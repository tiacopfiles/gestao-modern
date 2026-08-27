<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_cancellations', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('financial_title_id');
            $table->unsignedBigInteger('integration_client_id');
            $table->unsignedBigInteger('source_system_id');
            $table->text('reason');
            $table->string('correlation_id', 64);
            $table->timestamp('cancelled_at');
            $table->timestamps();

            $table->foreign('financial_title_id', 'title_cancellations_title_fk')
                ->references('id')->on('financial_titles')->restrictOnDelete();
            $table->foreign('integration_client_id', 'title_cancellations_client_fk')
                ->references('id')->on('integration_clients')->restrictOnDelete();
            $table->foreign('source_system_id', 'title_cancellations_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->unique('financial_title_id', 'title_cancellations_title_uq');
            $table->index(
                ['source_system_id', 'cancelled_at'],
                'title_cancellations_source_date_idx',
            );
            $table->index(
                ['integration_client_id', 'cancelled_at'],
                'title_cancellations_client_date_idx',
            );
            $table->index('correlation_id', 'title_cancellations_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_cancellations');
    }
};
