<?php

namespace Tests\Homologation;

use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\Money;
use App\Domain\Financial\TitleIngestionData;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\RefreshesTestDatabase;
use Tests\TestCase;

#[Group('mariadb')]
class MariaDbSchemaHomologationTest extends TestCase
{
    use RefreshesTestDatabase;

    public function test_server_schema_engine_charset_and_isolation_match_the_target(): void
    {
        $server = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS database_name, @@tx_isolation AS isolation_level');
        $this->assertMatchesRegularExpression('/^10\.1\./', $server->version);
        $this->assertSame((string) getenv('DB_DATABASE'), $server->database_name);
        $this->assertNotEmpty($server->isolation_level);

        $expected = [
            'documentos_modernos', 'source_systems', 'financial_titles', 'title_installments',
            'title_settlements', 'audit_events', 'integration_clients', 'integration_requests',
            'title_cancellations', 'import_batches', 'bank_transactions', 'import_batch_items',
            'reconciliation_sessions', 'reconciliation_matches', 'reconciliation_match_titles',
            'reconciliation_match_transactions', 'reconciliation_candidates',
            'reconciliation_candidate_titles', 'reconciliation_candidate_transactions',
            'reconciliation_exceptions',
        ];
        $prefix = DB::getTablePrefix();
        $tables = collect(DB::select(
            'SELECT table_name, engine, table_collation FROM information_schema.tables WHERE table_schema = ?',
            [(string) getenv('DB_DATABASE')],
        ))->keyBy('table_name');

        foreach ($expected as $logicalName) {
            $name = $prefix.$logicalName;
            $this->assertTrue($tables->has($name), "Tabela ausente: {$name}");
            $this->assertSame('InnoDB', $tables[$name]->engine, "Engine inesperado em {$name}");
            $this->assertStringStartsWith('utf8mb4_', $tables[$name]->table_collation, "Collation inesperada em {$name}");
        }
    }

    public function test_information_schema_confirms_decimal_longtext_indexes_and_foreign_keys(): void
    {
        $database = (string) getenv('DB_DATABASE');
        $prefix = DB::getTablePrefix();
        $decimals = [
            'financial_titles' => ['original_amount', 'discount_amount', 'addition_amount', 'total_amount'],
            'title_installments' => ['amount'], 'title_settlements' => ['amount'],
            'bank_transactions' => ['amount', 'balance_after'],
            'reconciliation_match_titles' => ['allocated_amount'],
            'reconciliation_match_transactions' => ['allocated_amount'],
            'reconciliation_candidate_titles' => ['proposed_amount'],
            'reconciliation_candidate_transactions' => ['proposed_amount'],
            'reconciliation_exceptions' => ['amount', 'difference_amount'],
        ];
        foreach ($decimals as $table => $columns) {
            foreach ($columns as $column) {
                $row = DB::selectOne(
                    'SELECT data_type, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                    [$database, $prefix.$table, $column],
                );
                $this->assertSame('decimal', $row->data_type, "{$table}.{$column}");
                $this->assertSame(15, (int) $row->numeric_precision, "{$table}.{$column}");
                $this->assertSame(2, (int) $row->numeric_scale, "{$table}.{$column}");
            }
        }

        foreach ([['reconciliation_candidates', 'evidence'], ['reconciliation_exceptions', 'evidence'], ['integration_requests', 'response_body']] as [$table, $column]) {
            $type = DB::selectOne(
                'SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
                [$database, $prefix.$table, $column],
            )->data_type;
            $this->assertSame('longtext', $type, "{$table}.{$column}");
        }

        $indexes = collect(DB::select(
            'SELECT DISTINCT index_name FROM information_schema.statistics WHERE table_schema = ?',
            [$database],
        ))->pluck('index_name');
        foreach ([
            'financial_titles_source_external_uq', 'integration_requests_client_key_uq',
            'bank_transactions_account_source_external_uq', 'reconciliation_candidate_signature_unique',
            'reconciliation_exception_signature_unique',
        ] as $name) {
            $this->assertContains($name, $indexes->all());
            $this->assertLessThanOrEqual(64, strlen($name));
        }

        $rules = collect(DB::select(
            'SELECT constraint_name, delete_rule FROM information_schema.referential_constraints WHERE constraint_schema = ?',
            [$database],
        ))->mapWithKeys(fn (object $row): array => [$row->constraint_name => $row->delete_rule]);
        $this->assertSame('RESTRICT', $rules['recon_candidates_session_fk'] ?? null);
        $this->assertSame('SET NULL', $rules['recon_candidates_match_fk'] ?? null);
        $this->assertSame('CASCADE', $rules['recon_candidate_titles_candidate_fk'] ?? null);
    }

    public function test_decimal_round_trip_and_installment_residual_are_exact(): void
    {
        foreach (['0.01', '0.10', '999999.99'] as $index => $amount) {
            $result = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
                sourceCode: 'API', externalId: "HML-DECIMAL-{$index}", type: FinancialTitleType::Payable,
                issueDate: '2026-08-01', dueDate: '2026-08-31', originalAmount: $amount,
            ));
            $stored = DB::table('financial_titles')->where('id', $result->title->id)->value('original_amount');
            $this->assertSame($amount, (string) $stored);
        }

        $result = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: 'HML-100-DIV-3', type: FinancialTitleType::Payable,
            issueDate: '2026-01-01', dueDate: '2026-01-31', originalAmount: '100.00', installmentCount: 3,
        ));
        $amounts = $result->title->installments()->orderBy('installment_number')->pluck('amount')->map(fn ($value): string => (string) $value)->all();
        $this->assertSame(['33.33', '33.33', '33.34'], $amounts);
        $sum = DB::table('title_installments')->where('financial_title_id', $result->title->id)->sum('amount');
        $this->assertSame(10000, Money::toCents((string) $sum));
    }

    public function test_restrict_cascade_and_set_null_are_enforced_by_innodb(): void
    {
        $now = now();
        $sessionId = DB::table('reconciliation_sessions')->insertGetId([
            'account_id' => 1, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
            'status' => 'OPEN', 'created_by' => 1, 'correlation_id' => (string) Str::uuid(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchId = DB::table('reconciliation_matches')->insertGetId([
            'reconciliation_session_id' => $sessionId, 'status' => 'CONFIRMED', 'method' => 'MANUAL',
            'confirmed_by' => 1, 'confirmed_at' => $now, 'correlation_id' => (string) Str::uuid(),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $candidateId = DB::table('reconciliation_candidates')->insertGetId([
            'reconciliation_session_id' => $sessionId, 'reconciliation_match_id' => $matchId,
            'type' => 'ONE_TO_ONE', 'status' => 'ACCEPTED', 'score' => 80, 'confidence' => 'HIGH',
            'engine_version' => 'hml-rules', 'signature_hash' => hash('sha256', 'fk-set-null'),
            'evidence' => '{}', 'generated_by' => 1, 'generated_at' => $now,
            'correlation_id' => (string) Str::uuid(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $title = app(TitleIngestionService::class)->ingest(new TitleIngestionData(
            sourceCode: 'API', externalId: 'HML-FK-CASCADE', type: FinancialTitleType::Payable,
            issueDate: '2026-08-01', dueDate: '2026-08-31', originalAmount: '1.00',
        ))->title->load('installments');
        DB::table('reconciliation_candidate_titles')->insert([
            'reconciliation_candidate_id' => $candidateId, 'financial_title_id' => $title->id,
            'title_installment_id' => $title->installments->first()->id, 'proposed_amount' => '1.00',
            'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('reconciliation_matches')->where('id', $matchId)->delete();
        $this->assertNull(DB::table('reconciliation_candidates')->where('id', $candidateId)->value('reconciliation_match_id'));

        try {
            DB::table('reconciliation_sessions')->where('id', $sessionId)->delete();
            $this->fail('RESTRICT deveria impedir a exclusão da sessão com candidato.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        DB::table('reconciliation_candidates')->where('id', $candidateId)->delete();
        $this->assertSame(0, DB::table('reconciliation_candidate_titles')->where('reconciliation_candidate_id', $candidateId)->count());
        $this->assertSame(1, DB::table('reconciliation_sessions')->where('id', $sessionId)->count());
    }
}
