<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_closure_matches', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_closure_id');
            $table->foreignId('reconciliation_match_id');
            $table->string('captured_status', 20);
            $table->decimal('captured_total_amount', 15, 2);
            $table->timestamps();

            $table->foreign('reconciliation_closure_id', 'recon_closure_matches_closure_fk')
                ->references('id')->on('reconciliation_closures')->restrictOnDelete();
            $table->foreign('reconciliation_match_id', 'recon_closure_matches_match_fk')
                ->references('id')->on('reconciliation_matches')->restrictOnDelete();
            $table->unique(['reconciliation_closure_id', 'reconciliation_match_id'], 'recon_closure_matches_uq');
            $table->index('reconciliation_match_id', 'recon_closure_matches_match_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_closure_matches');
    }
};
