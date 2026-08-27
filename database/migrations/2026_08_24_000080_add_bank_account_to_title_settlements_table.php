<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A conta bancária pertence à LIQUIDAÇÃO, não ao título.
 *
 * Esta é a coluna que encerra a convenção da "conta padrão da empresa"
 * (ADR-017). Até aqui, a única ligação entre uma baixa e um banco era um
 * palpite declarado no cadastro: `bank_accounts.is_default`. O custo desse
 * palpite foi medido — 311 linhas que o sistema tinha e nenhuma das três
 * planilhas do Itaú tinha, efeito líquido de -R$ 1.805.279,37 em 2026, quase
 * tudo pagamento que saiu por OUTRO banco e caiu na conciliação do Itaú.
 *
 * Fica na liquidação e não no título de propósito: o título é a obrigação
 * ("devo 1.200 ao fornecedor X"), a liquidação é o fato financeiro ("saíram
 * 1.200 da conta corrente 13377-9 no dia 21"). Quem tem banco é o fato. Um
 * título com baixa parcial em duas contas é representável assim, e não seria
 * se a coluna morasse no título.
 *
 * NULÁVEL, e isso é a decisão central: a sincronização com as origens legadas
 * NÃO tem como saber o banco — `contas` e `contasareceber` não têm a coluna,
 * conferido no `information_schema` das duas. Nulo significa "ainda não se sabe"
 * e vira pendência operacional explícita. Nunca preencher por convenção: é
 * exatamente o defeito que o ADR-017 encerra.
 *
 * Sem FK contra `bank_accounts` para manter o padrão do projeto nas colunas que
 * apontam para cadastro (ver ADR-009: `account_id` também não recebe FK); a
 * aplicação valida que a conta existe, está ativa e pertence à empresa do
 * título.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('title_settlements') || SchemaCompat::hasColumn('title_settlements', 'bank_account_id')) {
            return;
        }

        Schema::table('title_settlements', function (Blueprint $table): void {
            $table->unsignedBigInteger('bank_account_id')->nullable()->after('title_installment_id');

            // O índice que a conciliação usa a cada abertura: "tudo que caiu
            // NESTA conta NESTE dia". Sem ele, o recorte diário varre a tabela
            // inteira de liquidações a cada refresh.
            $table->index(
                ['bank_account_id', 'settlement_date', 'status'],
                'title_settlements_bank_date_status_idx',
            );

            // A fila de pendências pergunta o contrário: "o que está confirmado
            // e ainda sem banco". Um índice separado porque a coluna nula é o
            // filtro, e ele precisa ser seletivo enquanto a fila for pequena.
            $table->index(
                ['status', 'bank_account_id'],
                'title_settlements_status_bank_idx',
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('title_settlements') || ! SchemaCompat::hasColumn('title_settlements', 'bank_account_id')) {
            return;
        }

        Schema::table('title_settlements', function (Blueprint $table): void {
            $table->dropIndex('title_settlements_bank_date_status_idx');
            $table->dropIndex('title_settlements_status_bank_idx');
            $table->dropColumn('bank_account_id');
        });
    }
};
