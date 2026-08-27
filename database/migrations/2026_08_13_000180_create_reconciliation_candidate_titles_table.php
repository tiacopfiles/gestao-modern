<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_candidate_titles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_candidate_id');
            $table->foreignId('financial_title_id');
            $table->foreignId('title_installment_id');
            $table->decimal('proposed_amount', 15, 2);
            $table->timestamps();

            $table->foreign('reconciliation_candidate_id', 'recon_candidate_titles_candidate_fk')
                ->references('id')->on('reconciliation_candidates')->cascadeOnDelete();
            $table->foreign('financial_title_id', 'recon_candidate_titles_title_fk')
                ->references('id')->on('financial_titles')->restrictOnDelete();
            $table->foreign('title_installment_id', 'recon_candidate_titles_installment_fk')
                ->references('id')->on('title_installments')->restrictOnDelete();
            $table->unique(['reconciliation_candidate_id', 'title_installment_id'], 'reconciliation_candidate_title_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_candidate_titles');
    }
};
