<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_installments', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('financial_title_id');
            $table->unsignedInteger('installment_number');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('status', 30)->default('OPEN');
            $table->timestamps();

            $table->foreign('financial_title_id', 'title_installments_title_fk')
                ->references('id')->on('financial_titles')->restrictOnDelete();
            $table->unique(['financial_title_id', 'installment_number'], 'title_installments_number_uq');
            $table->index(['status', 'due_date'], 'title_installments_status_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_installments');
    }
};
