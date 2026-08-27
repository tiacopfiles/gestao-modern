<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Duas colunas que faltavam no movimento manual para ele conseguir registrar o
 * que hoje é digitado direto na planilha de conciliação.
 *
 * `document_number`: a planilha tem uma coluna "Nº.Doc." preenchida à mão
 * (NF.291, boleto, recibo). O movimento manual nascia sem ela, então um PIX de
 * nota fiscal entrava sem o número da nota e a linha ficava impossível de
 * conferir contra o extrato.
 *
 * `bank_account_id`: por qual conta bancária o dinheiro passou. Fica ANULÁVEL de
 * propósito — nulo significa "a conta padrão da empresa", que é o caso normal e
 * o que a planilha assume no cabeçalho. Preencher só é necessário quando o
 * movimento foge do padrão, e é justamente isso que torna o campo útil:
 * transferência entre contas do grupo e depósito que caiu em outro banco são os
 * dois casos que aparecem no histórico das planilhas o tempo todo
 * ("Transferência Itaú agência 4536 c/c 38821-0", "Depositou BB 30/06/2026").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('manual_movements')) {
            return;
        }

        Schema::table('manual_movements', function (Blueprint $table): void {
            if (! SchemaCompat::hasColumn('manual_movements', 'document_number')) {
                // 120 como em `financial_titles.document_number`, para as duas
                // fontes caberem na mesma coluna do relatório sem truncar
                // diferente uma da outra.
                $table->string('document_number', 120)->nullable()->after('account_id');
            }

            if (! SchemaCompat::hasColumn('manual_movements', 'bank_account_id')) {
                $table->unsignedBigInteger('bank_account_id')->nullable()->after('account_id');
                $table->index('bank_account_id', 'manual_movements_bank_account_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('manual_movements')) {
            return;
        }

        Schema::table('manual_movements', function (Blueprint $table): void {
            if (SchemaCompat::hasColumn('manual_movements', 'bank_account_id')) {
                $table->dropIndex('manual_movements_bank_account_idx');
                $table->dropColumn('bank_account_id');
            }

            if (SchemaCompat::hasColumn('manual_movements', 'document_number')) {
                $table->dropColumn('document_number');
            }
        });
    }
};
