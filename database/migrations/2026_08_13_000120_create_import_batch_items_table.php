<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batch_items', function (Blueprint $table): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedInteger('position');
            $table->string('external_id', 128)->nullable();
            $table->unsignedBigInteger('bank_transaction_id')->nullable();
            $table->string('result', 20);
            $table->string('error_code', 64)->nullable();
            $table->string('error_message', 500)->nullable();
            $table->char('raw_hash', 64);
            $table->longText('metadata')->nullable();
            $table->timestamps();

            $table->foreign('import_batch_id', 'import_batch_items_batch_fk')
                ->references('id')->on('import_batches')->restrictOnDelete();
            $table->foreign('bank_transaction_id', 'import_batch_items_transaction_fk')
                ->references('id')->on('bank_transactions')->restrictOnDelete();
            $table->unique(['import_batch_id', 'position'], 'import_batch_items_batch_position_uq');
            $table->index(['import_batch_id', 'result'], 'import_batch_items_batch_result_idx');
            $table->index('bank_transaction_id', 'import_batch_items_transaction_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_items');
    }
};
