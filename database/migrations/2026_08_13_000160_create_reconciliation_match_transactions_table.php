<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_match_transactions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('reconciliation_match_id');
            $table->unsignedBigInteger('bank_transaction_id');
            $table->decimal('allocated_amount', 15, 2);
            $table->timestamps();

            $table->foreign('reconciliation_match_id', 'reconciliation_match_transactions_match_fk')
                ->references('id')->on('reconciliation_matches')->restrictOnDelete();
            $table->foreign('bank_transaction_id', 'reconciliation_match_transactions_transaction_fk')
                ->references('id')->on('bank_transactions')->restrictOnDelete();
            $table->unique(
                ['reconciliation_match_id', 'bank_transaction_id'],
                'reconciliation_match_transactions_match_transaction_uq',
            );
            $table->index('bank_transaction_id', 'reconciliation_match_transactions_transaction_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_match_transactions');
    }
};
