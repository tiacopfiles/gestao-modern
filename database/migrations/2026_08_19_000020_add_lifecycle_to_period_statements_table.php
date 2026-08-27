<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A Conciliação deixa de ser um retrato de um único clique e passa a ter
 * ciclo de vida: criada ABERTA, evolui com "Atualizar" conforme o mês
 * acontece, e só vira definitiva quando alguém a FECHA.
 *
 * O snapshot continua sendo exatamente o que já era — as linhas gravadas em
 * `period_statement_lines`. Fechar não cria um sistema de fechamento novo;
 * só impede que `status` volte a ser alterado.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `generated_at` foi declarada `$table->timestamp('generated_at')`
        // (singular, sem `nullable()`), e é a primeira coluna TIMESTAMP da
        // tabela. Neste MariaDB 10.1, com `explicit_defaults_for_timestamp=0`,
        // isso dá a ela `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
        // por conta própria — confirmado em produção via `SHOW CREATE TABLE`.
        //
        // Isso nunca doeu porque o relatório era gerado uma vez só. Esta
        // entrega passa a salvar a mesma linha repetidamente ("Atualizar"), e
        // sem esta correção "Criada em" viraria sempre igual a "agora".
        //
        // DATETIME nunca teve esse comportamento implícito — é a mesma
        // correção já aplicada em `manual_movements`. SQLite não tem o
        // defeito (não existe ALTER ... MODIFY lá, e não precisa).
        if (DB::connection()->getDriverName() === 'mysql') {
            $tabela = DB::getTablePrefix().'period_statements';
            DB::statement("ALTER TABLE `{$tabela}` MODIFY `generated_at` DATETIME NOT NULL");
        }

        Schema::table('period_statements', function (Blueprint $table): void {
            $table->string('status', 10)->default('OPEN')->after('account_bank');
            $table->dateTime('last_synced_at')->nullable()->after('generated_at');
            $table->unsignedBigInteger('closed_by')->nullable()->after('last_synced_at');
            $table->dateTime('closed_at')->nullable()->after('closed_by');

            $table->index('status', 'period_statements_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('period_statements', function (Blueprint $table): void {
            $table->dropIndex('period_statements_status_idx');
            $table->dropColumn(['status', 'last_synced_at', 'closed_by', 'closed_at']);
        });

        // Não reverte o tipo de generated_at: já era um defeito antes desta
        // migration (mascarado por o relatório nunca ser salvo duas vezes), e
        // voltar para TIMESTAMP perigoso não devolve nada de útil.
    }
};
