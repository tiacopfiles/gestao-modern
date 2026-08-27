<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Movimento manual: dinheiro que entrou ou saiu da conta sem passar pelo Contas
 * a Pagar nem pelo Contas a Receber.
 *
 * PIX avulso, tarifa, rendimento, transferência entre contas, ajuste
 * operacional. O sistema antigo tinha isso (`movimento/create`) e a
 * conciliação dele somava esses lançamentos junto com pagamentos e
 * recebimentos; sem eles, o movimento do período conta só parte do caixa.
 *
 * É uma entidade PRÓPRIA, e não um título:
 *
 *  - um título representa uma obrigação que nasceu numa das origens e é lá que
 *    ela vive. Criar um título para registrar um PIX inventaria uma conta a
 *    pagar que não existe em lugar nenhum, e a próxima sincronização não
 *    saberia o que fazer com ele;
 *  - `bank_only_movements` também não serve: aquilo CLASSIFICA uma transação já
 *    importada de um OFX e exige `bank_transaction_id`. Aqui não há OFX.
 *
 * Criar um movimento manual não toca título, não cria baixa, não muda status e
 * não escreve nas origens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_movements', function (Blueprint $table): void {
            $table->id();

            // Mesma conta que os títulos usam (`contas.id`), para o movimento do
            // período conseguir somar as duas fontes na mesma linha do tempo.
            $table->unsignedInteger('account_id');

            $table->date('movement_date');

            // IN = entrada, OUT = saída. Só existem esses dois: "saldo" era um
            // terceiro tipo no sistema antigo, mas saldo inicial aqui é campo do
            // relatório, não lançamento.
            $table->string('direction', 3);

            // decimal(15,2) igual a `title_settlements.amount`, para as duas
            // fontes entrarem no mesmo cálculo sem conversão de tipo. Toda
            // aritmética acontece em centavos inteiros via Money.
            $table->decimal('amount', 15, 2);

            $table->string('history', 250);
            $table->unsignedInteger('category_id')->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('correlation_id', 64)->nullable();

            // DATETIME e não TIMESTAMP de propósito: neste MariaDB 10.1
            // `explicit_defaults_for_timestamp` está desligado, e a primeira
            // coluna TIMESTAMP da tabela ganha `ON UPDATE CURRENT_TIMESTAMP`
            // sozinha. Como este registro pode ser corrigido depois, `created_at`
            // seria reescrito na primeira edição e a data de criação sumiria.
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->dateTime('deleted_at')->nullable();

            $table->index(['account_id', 'movement_date'], 'manual_movements_account_date_idx');
            $table->index('movement_date');
        });

        Schema::table('period_statement_lines', function (Blueprint $table): void {
            // A linha do relatório aponta para o que a originou: título +
            // liquidação, ou movimento manual. Sem isso, depois de gravado não
            // dá para saber de onde veio uma entrada de R$ 2.500.
            $table->unsignedBigInteger('manual_movement_id')->nullable()->after('title_settlement_id');
        });
    }

    public function down(): void
    {
        Schema::table('period_statement_lines', function (Blueprint $table): void {
            $table->dropColumn('manual_movement_id');
        });

        Schema::dropIfExists('manual_movements');
    }
};
