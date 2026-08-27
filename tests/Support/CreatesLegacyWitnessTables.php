<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabelas legadas sintéticas usadas como testemunha de "não foi tocado".
 *
 * O schema precisa ser estruturalmente fiel ao legado, e não apenas conter a
 * coluna `marker`: telas ainda ativas (`/dashboard`, `/conciliacoes`,
 * `/fluxo-de-caixa`, financeiro) leem `valor_total`, `situacao`,
 * `data_vencimento` e afins. Com uma testemunha reduzida, essas rotas quebram
 * sob MariaDB — e, pior, passam sob SQLite: identificador desconhecido entre
 * aspas duplas é reinterpretado como literal string pelo SQLite, então
 * `sum("valor_total")` devolve 0 em vez de erro. Um verde SQLite, portanto, não
 * prova que a coluna existe; a fidelidade aqui é o que dá sentido ao assert.
 *
 * As colunas espelham `tools/demo/setup-sqlite.php`, que é a definição
 * sintética canônica do legado neste projeto.
 */
trait CreatesLegacyWitnessTables
{
    protected function createLegacyWitnessTables(): void
    {
        $this->createLegacyFinancialTable('lancamentos', receivable: false);
        $this->createLegacyFinancialTable('recebimentos', receivable: true);

        Schema::create('movimentos', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('marker')->default('');
            $table->string('id_conta')->default('');
            $table->date('data_referencia')->nullable();
            $table->string('descricao')->default('');
            $table->string('operacao', 20)->default('');
            $table->decimal('valor', 15, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        foreach (['lancamentos', 'recebimentos', 'movimentos'] as $table) {
            DB::table($table)->insert([
                'id' => 1,
                'marker' => 'intacto',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('conciliacoes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('id_conta');
            $table->date('data_inicial');
            $table->date('data_final');
            $table->string('mes');
            $table->string('ano');
            $table->string('status');
            $table->date('data_cadastro');
            $table->timestamps();
            $table->softDeletes();
        });
        DB::table('conciliacoes')->insert([
            'id' => 1,
            'id_conta' => '1',
            'data_inicial' => '2026-07-01',
            'data_final' => '2026-07-31',
            'mes' => '07',
            'ano' => '2026',
            'status' => 'ABERTA',
            'data_cadastro' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createLegacyFinancialTable(string $name, bool $receivable): void
    {
        Schema::create($name, function (Blueprint $table) use ($receivable): void {
            $table->increments('id');
            $table->string('marker')->default('');
            if ($receivable) {
                $table->string('cliente')->default('');
            }
            $table->string('fornecedor')->default('');
            foreach ([
                'numero_doc', 'tipo', 'categoria', 'conta', 'centrocusto',
                'situacao', 'pc', 'numero_pc', 'competencia', 'obs', 'tipo_lancamento',
            ] as $column) {
                $table->string($column)->default('');
            }
            $table->date('data_emissao')->nullable();
            $table->date('data_vencimento')->nullable();
            $table->date('data_pagamento')->nullable();
            foreach (['valor', 'acrescimo', 'desconto', 'valor_total'] as $column) {
                $table->decimal($column, 15, 2)->default(0);
            }
            $table->timestamps();
            $table->softDeletes();
        });
    }
}
