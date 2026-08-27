<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_closure_metrics', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_closure_id');
            $table->string('metric_key', 60);
            $table->decimal('metric_value', 20, 4)->nullable();
            $table->string('metric_value_text', 191)->nullable();
            $table->timestamps();

            $table->foreign('reconciliation_closure_id', 'recon_closure_metrics_closure_fk')
                ->references('id')->on('reconciliation_closures')->restrictOnDelete();
            $table->unique(['reconciliation_closure_id', 'metric_key'], 'recon_closure_metrics_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_closure_metrics');
    }
};
