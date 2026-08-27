<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_candidate_transactions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_candidate_id');
            $table->foreignId('bank_transaction_id');
            $table->decimal('proposed_amount', 15, 2);
            $table->timestamps();

            $table->foreign('reconciliation_candidate_id', 'recon_candidate_tx_candidate_fk')
                ->references('id')->on('reconciliation_candidates')->cascadeOnDelete();
            $table->foreign('bank_transaction_id', 'recon_candidate_tx_bank_fk')
                ->references('id')->on('bank_transactions')->restrictOnDelete();
            $table->unique(['reconciliation_candidate_id', 'bank_transaction_id'], 'reconciliation_candidate_transaction_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_candidate_transactions');
    }
};
