<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A conciliação passa a ser de uma CONTA BANCÁRIA, e ganha o bloco de
 * pendências que existe nas planilhas.
 *
 * `period_statements.bank_account_id` — hoje a conciliação é só da empresa, e
 * `account_bank` é um texto solto copiado de `contas.banco`. Nas planilhas o
 * recorte real é empresa + conta bancária (o cabeçalho tem as duas linhas), e é
 * a conta bancária que tem extrato e saldo. Anulável para não invalidar as
 * conciliações já gravadas, que nasceram sem esse recorte.
 *
 * `period_statement_lines.section` — a aba do mês corrente termina com um bloco
 * de linhas SEM data e SEM saldo: títulos já conhecidos que ainda não caíram no
 * banco. Não são movimento (não entram no saldo corrido nem nos totais), mas
 * fazem parte do relatório. `LEDGER` é o extrato conciliado; `PENDING` é esse
 * rodapé.
 *
 * `running_balance_cents` continua NOT NULL e recebe 0 nas linhas PENDING, que
 * é ignorado porque `section` já diz que ali não há saldo. Tornar a coluna
 * anulável exigiria um `change()`, e neste MariaDB 10.1 introspecção de coluna
 * quebra (`generation_expression` só existe a partir do 10.2) — o mesmo motivo
 * que obrigou a existir o `SchemaCompat`. Não vale derrubar uma migration em
 * produção para trocar um zero ignorado por um NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('period_statements') && ! SchemaCompat::hasColumn('period_statements', 'bank_account_id')) {
            Schema::table('period_statements', function (Blueprint $table): void {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('account_bank');
                $table->index(['bank_account_id', 'period_start'], 'period_statements_bank_period_idx');
            });
        }

        if (Schema::hasTable('period_statement_lines') && ! SchemaCompat::hasColumn('period_statement_lines', 'section')) {
            Schema::table('period_statement_lines', function (Blueprint $table): void {
                $table->string('section', 10)->default('LEDGER')->after('line_number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('period_statement_lines') && SchemaCompat::hasColumn('period_statement_lines', 'section')) {
            Schema::table('period_statement_lines', function (Blueprint $table): void {
                $table->dropColumn('section');
            });
        }

        if (Schema::hasTable('period_statements') && SchemaCompat::hasColumn('period_statements', 'bank_account_id')) {
            Schema::table('period_statements', function (Blueprint $table): void {
                $table->dropIndex('period_statements_bank_period_idx');
                $table->dropColumn('bank_account_id');
            });
        }
    }
};
