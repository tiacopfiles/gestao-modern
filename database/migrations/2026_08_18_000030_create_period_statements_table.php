<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Movimento do período: o relatório de entradas e saídas de uma conta, com
 * saldo corrido, gerado a partir das liquidações dos títulos.
 *
 * As linhas ficam GRAVADAS, não recalculadas na hora de abrir. A origem é um
 * sistema vivo — as funcionárias seguem lançando e dando baixa — então um
 * relatório recalculado mostraria números diferentes a cada visita, e um
 * registro de período que muda sozinho não serve para conferir nada.
 *
 * Gerar um movimento NÃO altera nenhum título, nenhuma liquidação e nenhum
 * status: é leitura da base mais um registro novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_statements', function (Blueprint $table): void {
            $table->id();

            $table->unsignedInteger('account_id');
            $table->string('account_name', 191);          // congelado no momento da geração
            $table->string('account_bank', 120)->nullable();

            $table->date('period_start');
            $table->date('period_end');

            // Tudo em centavos inteiros: o legado acumulava saldo em float.
            $table->bigInteger('opening_balance_cents')->default(0);
            $table->bigInteger('closing_balance_cents')->default(0);
            $table->bigInteger('total_in_cents')->default(0);
            $table->bigInteger('total_out_cents')->default(0);
            $table->unsignedInteger('line_count')->default(0);

            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at');
            $table->string('correlation_id', 64)->nullable();

            $table->timestamps();

            $table->index(['account_id', 'period_start', 'period_end'], 'period_statements_account_period_idx');
            $table->index('generated_at');
        });

        Schema::create('period_statement_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('period_statement_id');

            $table->unsignedInteger('line_number');

            $table->date('movement_date');
            $table->string('document_number', 120)->nullable();
            $table->string('origin_id', 64)->nullable();    // o id do lançamento no sistema de origem
            $table->string('history', 255);
            $table->date('due_date')->nullable();

            // Uma linha é entrada OU saída; a outra fica nula, não zero, para a
            // tela poder deixar a célula vazia como no relatório antigo.
            $table->bigInteger('amount_in_cents')->nullable();
            $table->bigInteger('amount_out_cents')->nullable();
            $table->bigInteger('running_balance_cents');

            $table->unsignedBigInteger('financial_title_id')->nullable();
            $table->unsignedBigInteger('title_settlement_id')->nullable();

            $table->timestamps();

            $table->foreign('period_statement_id', 'period_statement_lines_statement_fk')
                ->references('id')->on('period_statements')->cascadeOnDelete();

            $table->index(['period_statement_id', 'line_number'], 'period_statement_lines_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_statement_lines');
        Schema::dropIfExists('period_statements');
    }
};
