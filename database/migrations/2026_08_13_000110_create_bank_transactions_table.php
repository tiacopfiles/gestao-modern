<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedInteger('account_id');
            $table->unsignedBigInteger('source_system_id');
            $table->unsignedBigInteger('import_batch_id');
            $table->string('external_id', 128);
            $table->string('identity_quality', 20)->default('STRONG');
            $table->string('direction', 10);
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3)->default('BRL');
            $table->date('transaction_date');
            $table->timestamp('posted_at')->nullable();
            $table->text('description_original');
            $table->string('document_number', 120)->nullable();
            $table->string('bank_reference', 191)->nullable();
            $table->string('end_to_end_id', 191)->nullable();
            $table->string('counterparty_name', 191)->nullable();
            $table->string('counterparty_document', 30)->nullable();
            $table->decimal('balance_after', 15, 2)->nullable();
            $table->char('payload_hash', 64);
            $table->char('raw_hash', 64)->nullable();
            $table->timestamps();

            $table->foreign('source_system_id', 'bank_transactions_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->foreign('import_batch_id', 'bank_transactions_batch_fk')
                ->references('id')->on('import_batches')->restrictOnDelete();
            $table->unique(
                ['account_id', 'source_system_id', 'external_id'],
                'bank_transactions_account_source_external_uq',
            );
            $table->index(['account_id', 'transaction_date'], 'bank_transactions_account_date_idx');
            $table->index(
                ['account_id', 'direction', 'transaction_date'],
                'bank_transactions_account_direction_date_idx',
            );
            $table->index(['source_system_id', 'external_id'], 'bank_transactions_source_external_idx');
            $table->index('import_batch_id', 'bank_transactions_batch_idx');
            $table->index('end_to_end_id', 'bank_transactions_end_to_end_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
