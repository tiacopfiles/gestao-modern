<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

class MigrationSafetyTest extends TestCase
{
    use RefreshesTestDatabase;

    public function test_only_additive_phase_one_tables_are_created_on_a_clean_database(): void
    {
        foreach ([
            'source_systems',
            'financial_titles',
            'title_installments',
            'title_settlements',
            'audit_events',
            'import_batches',
            'bank_transactions',
            'import_batch_items',
            'reconciliation_sessions',
            'reconciliation_matches',
            'reconciliation_match_titles',
            'reconciliation_match_transactions',
            'reconciliation_candidates',
            'reconciliation_candidate_titles',
            'reconciliation_candidate_transactions',
            'reconciliation_exceptions',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabela aditiva ausente: {$table}");
        }

        foreach (['users', 'cache', 'jobs', 'lancamentos', 'recebimentos', 'movimentos', 'conciliacoes'] as $legacyOrScaffoldTable) {
            $this->assertFalse(
                Schema::hasTable($legacyOrScaffoldTable),
                "A baseline não deve criar a tabela {$legacyOrScaffoldTable}.",
            );
        }
    }

    public function test_new_financial_amounts_use_decimal_columns(): void
    {
        foreach (['original_amount', 'discount_amount', 'addition_amount', 'total_amount'] as $column) {
            $this->assertMoneyColumn('financial_titles', $column);
        }

        $this->assertMoneyColumn('title_installments', 'amount');
        $this->assertMoneyColumn('title_settlements', 'amount');
        $this->assertMoneyColumn('bank_transactions', 'amount');
        $this->assertMoneyColumn('bank_transactions', 'balance_after');
        $this->assertMoneyColumn('reconciliation_match_titles', 'allocated_amount');
        $this->assertMoneyColumn('reconciliation_match_transactions', 'allocated_amount');
        $this->assertMoneyColumn('reconciliation_candidate_titles', 'proposed_amount');
        $this->assertMoneyColumn('reconciliation_candidate_transactions', 'proposed_amount');
        $this->assertMoneyColumn('reconciliation_exceptions', 'amount');
        $this->assertMoneyColumn('reconciliation_exceptions', 'difference_amount');
    }

    public function test_phase_six_closure_tables_are_created_and_money_stays_decimal(): void
    {
        foreach ([
            'reconciliation_closures',
            'reconciliation_closure_matches',
            'reconciliation_closure_exceptions',
            'reconciliation_closure_metrics',
            'reconciliation_reopenings',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabela da Fase 6 ausente: {$table}");
        }

        // Único valor monetário capturado no fechamento. `metric_value` é
        // deliberadamente decimal(20,4) porque carrega métricas, não dinheiro.
        $this->assertMoneyColumn('reconciliation_closure_matches', 'captured_total_amount');
    }

    /**
     * Dinheiro precisa ser DECIMAL, nunca ponto flutuante.
     *
     * A introspecção usa `information_schema` no MySQL/MariaDB porque
     * `Schema::getColumnType()` do Laravel consulta `generation_expression`,
     * coluna que só existe a partir do MariaDB 10.2 — o alvo de produção é
     * 10.1.x. No MariaDB a precisão declarada também é verificada.
     */
    private function assertMoneyColumn(string $table, string $column): void
    {
        $connection = Schema::getConnection();

        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertContains(
                Schema::getColumnType($table, $column),
                ['decimal', 'numeric'],
                "{$table}.{$column} deve ser decimal.",
            );

            return;
        }

        $row = $connection->selectOne(
            'select data_type, numeric_precision, numeric_scale
             from information_schema.columns
             where table_schema = database() and table_name = ? and column_name = ?',
            [$connection->getTablePrefix().$table, $column],
        );

        $this->assertNotNull($row, "Coluna ausente: {$table}.{$column}");
        $this->assertContains(strtolower((string) $row->data_type), ['decimal', 'numeric'], "{$table}.{$column} deve ser decimal.");
        $this->assertSame(15, (int) $row->numeric_precision, "{$table}.{$column} deve ter precisão 15.");
        $this->assertSame(2, (int) $row->numeric_scale, "{$table}.{$column} deve ter escala 2.");
    }

    public function test_source_catalog_contains_the_required_extensible_baseline(): void
    {
        $this->assertDatabaseHas('source_systems', ['code' => 'MANUAL', 'active' => true]);
        $this->assertDatabaseHas('source_systems', ['code' => 'LEGACY_PAYABLE', 'active' => true]);
        $this->assertDatabaseHas('source_systems', ['code' => 'LEGACY_RECEIVABLE', 'active' => true]);
        $this->assertDatabaseHas('source_systems', ['code' => 'AGROCOLITTI', 'active' => true]);
        $this->assertDatabaseHas('source_systems', ['code' => 'ACOP_FILES', 'active' => true]);
        $this->assertDatabaseHas('source_systems', ['code' => 'NFSE', 'active' => true]);
        $this->assertDatabaseHas('source_systems', ['code' => 'BANK_IMPORT', 'active' => true]);
        $this->assertDatabaseHas('source_systems', ['code' => 'API', 'active' => true]);
    }

    public function test_phase_five_uses_innodb_and_mariadb_safe_explicit_constraint_names(): void
    {
        $files = glob(database_path('migrations/2026_08_13_000{170,180,190,200}_*.php'), GLOB_BRACE);
        $this->assertCount(4, $files);

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            $this->assertStringContainsString("\$table->engine = 'InnoDB';", $contents);
            $this->assertStringNotContainsString('->constrained(', $contents);
            preg_match_all("/->foreign\([^;]+?,\s*'([^']+)'\)/s", $contents, $matches);
            $this->assertNotEmpty($matches[1], basename($file).' deve nomear FKs explicitamente.');
            foreach ($matches[1] as $identifier) {
                $this->assertLessThanOrEqual(64, strlen($identifier), "Identificador MariaDB excede 64 caracteres: {$identifier}");
            }
        }
    }
}
