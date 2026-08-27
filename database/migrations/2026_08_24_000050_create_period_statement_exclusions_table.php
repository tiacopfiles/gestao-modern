<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Linha que a conciliação puxou mas que não passou por aquela conta bancária.
 *
 * O caso real: a conta do Itaú é da empresa, e nem todo pagamento sai por ela.
 * Quando alguém paga por fora — PIX, outra conta do grupo — o lançamento existe
 * no Contas a Pagar, mas nunca tocou aquele extrato. Como as origens não
 * guardam banco (não existe a coluna), a conciliação puxa tudo da empresa e
 * essas linhas entram indevidamente: em 2026 são R$ 1,3 milhão a mais de saídas
 * na Acop Files e na Global Box, enquanto a Duemagem — que paga tudo pela conta
 * dela — fecha com 0,2% a 1,7% de diferença.
 *
 * Não dá para descobrir isso sozinho. Tentei: nem `tipo`, nem `categoria`, nem
 * o `obs` distinguem ("Pagamento on-line" está em 97% dos lançamentos). Quem
 * sabe é quem concilia, e é ela quem marca.
 *
 * A exclusão vale para UMA conciliação, e é reversível: sai da lista, some do
 * saldo, e um clique traz de volta. Nada é apagado — nem título, nem
 * liquidação, nem movimento manual. Só deixa de contar naquele extrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_statement_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('period_statement_id');

            // A identidade da linha é a mesma que o `refresh()` usa para saber o
            // que é novo: a liquidação ou o movimento manual que a originou.
            // Uma das duas está preenchida, nunca as duas.
            $table->unsignedBigInteger('title_settlement_id')->nullable();
            $table->unsignedBigInteger('manual_movement_id')->nullable();

            $table->string('reason', 250)->nullable();
            $table->unsignedBigInteger('excluded_by')->nullable();

            // DATETIME, não TIMESTAMP: neste MariaDB 10.1
            // `explicit_defaults_for_timestamp` está desligado e a primeira
            // coluna TIMESTAMP ganharia `ON UPDATE CURRENT_TIMESTAMP` sozinha.
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('period_statement_id', 'period_statement_exclusions_statement_fk')
                ->references('id')->on('period_statements')->cascadeOnDelete();

            $table->index('period_statement_id', 'period_statement_exclusions_statement_idx');

            // A mesma linha não pode ser excluída duas vezes na mesma
            // conciliação — a garantia fica no banco, não na tela.
            $table->unique(
                ['period_statement_id', 'title_settlement_id'],
                'period_statement_exclusions_settlement_uq',
            );
            $table->unique(
                ['period_statement_id', 'manual_movement_id'],
                'period_statement_exclusions_manual_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_statement_exclusions');
    }
};
