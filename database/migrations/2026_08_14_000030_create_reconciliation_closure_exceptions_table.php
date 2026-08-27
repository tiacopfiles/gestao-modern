<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_closure_exceptions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_closure_id');
            $table->foreignId('reconciliation_exception_id');
            $table->string('captured_status', 16);
            $table->string('captured_type', 48);
            $table->timestamps();

            $table->foreign('reconciliation_closure_id', 'recon_closure_exceptions_closure_fk')
                ->references('id')->on('reconciliation_closures')->restrictOnDelete();
            $table->foreign('reconciliation_exception_id', 'recon_closure_exceptions_exception_fk')
                ->references('id')->on('reconciliation_exceptions')->restrictOnDelete();
            $table->unique(['reconciliation_closure_id', 'reconciliation_exception_id'], 'recon_closure_exceptions_uq');
            $table->index('reconciliation_exception_id', 'recon_closure_exceptions_exception_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_closure_exceptions');
    }
};
