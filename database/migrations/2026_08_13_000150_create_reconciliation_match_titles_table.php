<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_match_titles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('reconciliation_match_id');
            $table->unsignedBigInteger('financial_title_id');
            $table->unsignedBigInteger('title_installment_id')->nullable();
            $table->decimal('allocated_amount', 15, 2);
            $table->timestamps();

            $table->foreign('reconciliation_match_id', 'reconciliation_match_titles_match_fk')
                ->references('id')->on('reconciliation_matches')->restrictOnDelete();
            $table->foreign('financial_title_id', 'reconciliation_match_titles_title_fk')
                ->references('id')->on('financial_titles')->restrictOnDelete();
            $table->foreign('title_installment_id', 'reconciliation_match_titles_installment_fk')
                ->references('id')->on('title_installments')->restrictOnDelete();
            $table->unique(
                ['reconciliation_match_id', 'title_installment_id'],
                'reconciliation_match_titles_match_installment_uq',
            );
            $table->index('financial_title_id', 'reconciliation_match_titles_title_idx');
            $table->index('title_installment_id', 'reconciliation_match_titles_installment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_match_titles');
    }
};
