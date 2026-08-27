<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Conta bancária PADRÃO da empresa.
 *
 * As origens (`contas` e `contasareceber`) não guardam banco em lugar nenhum —
 * conferido coluna a coluna no `information_schema` das duas: a única coluna de
 * conta é `conta`, um texto com o nome da EMPRESA ("Acop Files", "Global Box",
 * "Duemagem"). Ou seja, não há como saber pela origem por qual banco um
 * pagamento saiu.
 *
 * O que existe é o costume: cada planilha de conciliação é de uma empresa e tem
 * uma conta no cabeçalho, sempre a mesma. É esse vínculo que esta coluna
 * registra — uma vez, explicitamente, por cadastro — para a conciliação saber a
 * qual conta bancária atribuir as liquidações que vêm das origens.
 *
 * É uma CONVENÇÃO declarada, não um dado apurado. Quando o movimento fugir do
 * padrão (um depósito que caiu em outro banco), quem corrige é o movimento
 * manual, que aponta para a conta bancária certa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bank_accounts') || SchemaCompat::hasColumn('bank_accounts', 'is_default')) {
            return;
        }

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->boolean('is_default')->default(false)->after('active');

            // A busca real é sempre "qual é a conta padrão desta empresa".
            $table->index(['company_id', 'is_default'], 'bank_accounts_company_default_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bank_accounts') || ! SchemaCompat::hasColumn('bank_accounts', 'is_default')) {
            return;
        }

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropIndex('bank_accounts_company_default_idx');
            $table->dropColumn('is_default');
        });
    }
};
