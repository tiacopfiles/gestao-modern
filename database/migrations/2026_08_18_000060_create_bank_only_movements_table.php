<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Classificação de movimento exclusivamente bancário.
 *
 * Tarifa, rendimento e transferência entre contas do grupo aparecem no extrato e
 * não têm título — nem deveriam ter. Sem um lugar para dizer isso, eles ficariam
 * para sempre na fila de "título não encontrado", e uma pendência que nunca sai
 * ensina o operador a ignorar a fila inteira.
 *
 * A classificação é um registro SEPARADO, ligado à transação. Não cria título,
 * não cria match e não altera o fato bancário: só declara, com autor e data, que
 * aquele movimento é do banco e está explicado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_only_movements', function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('bank_transaction_id');
            $table->string('kind', 30);
            $table->text('justification')->nullable();

            $table->unsignedBigInteger('classified_by')->nullable();
            $table->timestamp('classified_at');
            $table->string('correlation_id', 64)->nullable();

            $table->timestamps();

            // Uma transação tem no máximo uma classificação: duas explicações
            // para o mesmo movimento seriam duas verdades sobre o mesmo dinheiro.
            $table->unique('bank_transaction_id', 'bank_only_movements_transaction_uq');

            $table->foreign('bank_transaction_id', 'bank_only_movements_transaction_fk')
                ->references('id')->on('bank_transactions')->cascadeOnDelete();

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_only_movements');
    }
};
