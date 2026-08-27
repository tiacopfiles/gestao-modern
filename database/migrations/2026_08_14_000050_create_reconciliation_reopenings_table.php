<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_reopenings', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_closure_id');
            $table->unsignedBigInteger('reopened_by');
            $table->timestamp('reopened_at');
            $table->text('reason');
            $table->string('previous_status', 16);
            $table->string('resulting_session_status', 20);
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->foreign('reconciliation_closure_id', 'recon_reopenings_closure_fk')
                ->references('id')->on('reconciliation_closures')->restrictOnDelete();
            $table->index(['reconciliation_closure_id', 'reopened_at'], 'recon_reopenings_closure_date_idx');
            $table->index('correlation_id', 'recon_reopenings_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_reopenings');
    }
};
