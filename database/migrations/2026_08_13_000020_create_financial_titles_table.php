<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_titles', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('type', 20);
            $table->unsignedBigInteger('source_system_id');
            $table->string('external_id', 128)->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->char('payload_hash', 64);
            $table->string('party_type', 30)->nullable();
            $table->unsignedInteger('party_id')->nullable();
            $table->string('party_name', 191)->nullable();
            $table->string('document_number', 120)->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->decimal('original_amount', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('addition_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->char('currency', 3)->default('BRL');
            $table->unsignedInteger('account_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->unsignedInteger('cost_center_id')->nullable();
            $table->string('status', 30)->default('OPEN');
            $table->text('notes')->nullable();
            $table->string('legacy_type', 30)->nullable();
            $table->unsignedInteger('legacy_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('source_system_id', 'financial_titles_source_fk')
                ->references('id')->on('source_systems')->restrictOnDelete();
            $table->unique(['source_system_id', 'external_id'], 'financial_titles_source_external_uq');
            $table->unique(['source_system_id', 'idempotency_key'], 'financial_titles_source_idempotency_uq');
            $table->unique(['legacy_type', 'legacy_id'], 'financial_titles_legacy_uq');
            $table->index(['type', 'status', 'due_date'], 'financial_titles_type_status_due_idx');
            $table->index(['party_type', 'party_id'], 'financial_titles_party_idx');
            $table->index('account_id', 'financial_titles_account_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_titles');
    }
};
