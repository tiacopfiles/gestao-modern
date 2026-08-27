<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_exceptions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('reconciliation_session_id');
            $table->foreignId('financial_title_id')->nullable();
            $table->foreignId('title_installment_id')->nullable();
            $table->foreignId('bank_transaction_id')->nullable();
            $table->string('type', 48);
            $table->string('status', 16)->default('OPEN');
            $table->decimal('amount', 15, 2)->nullable();
            $table->decimal('difference_amount', 15, 2)->nullable();
            $table->longText('evidence');
            $table->string('engine_version', 40);
            $table->char('signature_hash', 64);
            $table->unsignedBigInteger('generated_by');
            $table->timestamp('generated_at');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_reason')->nullable();
            $table->uuid('correlation_id');
            $table->timestamps();

            $table->foreign('reconciliation_session_id', 'recon_exceptions_session_fk')
                ->references('id')->on('reconciliation_sessions')->restrictOnDelete();
            $table->foreign('financial_title_id', 'recon_exceptions_title_fk')
                ->references('id')->on('financial_titles')->restrictOnDelete();
            $table->foreign('title_installment_id', 'recon_exceptions_installment_fk')
                ->references('id')->on('title_installments')->restrictOnDelete();
            $table->foreign('bank_transaction_id', 'recon_exceptions_bank_fk')
                ->references('id')->on('bank_transactions')->restrictOnDelete();
            $table->unique(['reconciliation_session_id', 'engine_version', 'signature_hash'], 'reconciliation_exception_signature_unique');
            $table->index(['reconciliation_session_id', 'status', 'type'], 'reconciliation_exception_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
    }
};
