<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_settlements', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('financial_title_id');
            $table->unsignedBigInteger('title_installment_id')->nullable();
            $table->date('settlement_date');
            $table->decimal('amount', 15, 2);
            $table->string('type', 20);
            $table->string('status', 20)->default('CONFIRMED');
            $table->unsignedBigInteger('source_system_id')->nullable();
            $table->string('external_id', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->longText('metadata')->nullable();
            $table->timestamps();

            $table->foreign('financial_title_id', 'title_settlements_title_fk')
                ->references('id')->on('financial_titles')->restrictOnDelete();
            $table->foreign('title_installment_id', 'title_settlements_installment_fk')
                ->references('id')->on('title_installments')->restrictOnDelete();
            $table->foreign('source_system_id', 'title_settlements_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->unique(['source_system_id', 'external_id'], 'title_settlements_source_external_uq');
            $table->unique(['source_system_id', 'idempotency_key'], 'title_settlements_source_idempotency_uq');
            $table->index(['financial_title_id', 'status', 'settlement_date'], 'title_settlements_title_status_date_idx');
            $table->index('title_installment_id', 'title_settlements_installment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_settlements');
    }
};
