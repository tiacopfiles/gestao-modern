<?php

use App\Support\SchemaCompat;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Identidade dos cadastros vindos das origens.
 *
 * Deduplicar fornecedor e cliente pelo NOME está errado nos dois sentidos, e os
 * dados reais provaram os dois:
 *
 *   - funde quem não deve ser fundido: "Diego Donizete da Cunha Silva" são duas
 *     pessoas diferentes em `contasareceber`, CPF 334.003.928-39 (Indaiatuba) e
 *     309.364.398-82 (Salto);
 *   - e ainda assim duplica, porque o nome gravado passa por normalizações
 *     (truncamento, fallback de razão social vazia) que a chave não repetia.
 *
 * O documento também não serve: "001" aparece como CNPJ de fornecedores
 * diferentes em `contas`.
 *
 * O que identifica um cadastro é o par (origem, id na origem) — a mesma regra
 * que já vale para os títulos com `source_system` + `external_id`. É único,
 * estável e não depende de texto digitado por gente.
 */
return new class extends Migration
{
    private const TABELAS = ['fornecedores', 'clientes'];

    public function up(): void
    {
        // `fornecedores` e `clientes` vieram por mysqldump do banco legado, onde
        // `created_at` é `timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'`. O
        // ALTER TABLE reconstrói a tabela inteira, e o modo estrito recusa a data
        // zero — a migration morre sem nem chegar a criar a coluna.
        //
        // O modo é afrouxado só nesta sessão e só para o rebuild; nenhuma data
        // zero nova é gravada por causa disso.
        $this->relaxarModoEstrito();

        foreach (self::TABELAS as $tabela) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            if (! SchemaCompat::hasColumn($tabela, 'origem_sistema')) {
                Schema::table($tabela, function (Blueprint $table): void {
                    $table->string('origem_sistema', 40)->nullable();
                    $table->unsignedBigInteger('origem_id')->nullable();
                });
            }

            // Índice único: reimportar reconhece; e uma segunda gravação do
            // mesmo registro de origem passa a ser impossível, não só improvável.
            $indice = $tabela.'_origem_uq';
            try {
                Schema::table($tabela, function (Blueprint $table) use ($indice): void {
                    $table->unique(['origem_sistema', 'origem_id'], $indice);
                });
            } catch (Throwable) {
                // Índice já existe: nada a fazer.
            }
        }
    }

    private function relaxarModoEstrito(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        try {
            $atual = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS modo')->modo;

            $novo = collect(explode(',', $atual))
                ->reject(fn (string $m): bool => in_array(trim($m), [
                    'STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE',
                ], true))
                ->implode(',');

            DB::statement('SET SESSION sql_mode = ?', [$novo]);
        } catch (Throwable) {
            // Se não der para ajustar, segue: a migration ainda pode funcionar
            // em bancos cujas tabelas não tenham data zero.
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            if (! Schema::hasTable($tabela) || ! SchemaCompat::hasColumn($tabela, 'origem_sistema')) {
                continue;
            }

            Schema::table($tabela, function (Blueprint $table) use ($tabela): void {
                try {
                    $table->dropUnique($tabela.'_origem_uq');
                } catch (Throwable) {
                }
                $table->dropColumn(['origem_sistema', 'origem_id']);
            });
        }
    }
};
