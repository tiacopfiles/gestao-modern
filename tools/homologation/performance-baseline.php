<?php

declare(strict_types=1);

use Acop\Homologation\HomologationGuard;
use App\Application\Reconciliation\ReconciliationMatchingEngine;
use App\Domain\Financial\Money;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/HomologationGuard.php';

HomologationGuard::assertSafe();

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$sizes = in_array('--include-10000', $argv, true) ? [100, 1000, 10000] : [100, 1000];
$report = [
    'classification' => 'OBSERVATIONAL_BASELINE_NOT_AN_SLA',
    'generated_at' => gmdate(DATE_ATOM),
    'server' => HomologationGuard::assertSafe(),
    'datasets' => [],
];

foreach ($sizes as $size) {
    HomologationGuard::assertSafe();
    $migrationStarted = hrtime(true);
    $exit = Artisan::call('migrate:fresh', ['--force' => true]);
    if ($exit !== 0) {
        throw new RuntimeException('migrate:fresh falhou no baseline: '.Artisan::output());
    }
    $migrationMs = elapsedMilliseconds($migrationStarted);

    config([
        'reconciliation.v2_enabled' => true,
        'reconciliation.matching_enabled' => true,
        'reconciliation_matching.max_candidate_pool' => min(200, $size),
        'reconciliation_matching.max_composition_pool' => 0,
    ]);

    $seedStarted = hrtime(true);
    $now = date('Y-m-d H:i:s');
    $sourceId = DB::table('source_systems')->insertGetId([
        'code' => 'HML_PERF', 'name' => 'Fonte sintética de performance',
        'type' => 'BANK_IMPORT', 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $batchId = DB::table('import_batches')->insertGetId([
        'source_system_id' => $sourceId, 'account_id' => 1, 'channel' => 'HOMOLOGATION',
        'format' => 'SYNTHETIC', 'status' => 'COMPLETED', 'total_items' => $size,
        'imported_items' => $size, 'duplicate_items' => 0, 'rejected_items' => 0,
        'correlation_id' => 'hml-perf-batch-'.$size, 'started_at' => $now,
        'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    $sessionId = DB::table('reconciliation_sessions')->insertGetId([
        'account_id' => 1, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        'status' => 'OPEN', 'created_by' => 1, 'correlation_id' => 'hml-perf-session-'.$size,
        'created_at' => $now, 'updated_at' => $now,
    ]);

    foreach (array_chunk(range(1, $size), 500) as $indexes) {
        $titles = [];
        foreach ($indexes as $index) {
            $amount = Money::fromCents(10000 + $index);
            $titles[] = [
                'type' => 'PAYABLE', 'source_system_id' => $sourceId,
                'external_id' => 'HML-PERF-TITLE-'.$size.'-'.$index,
                'payload_hash' => hash('sha256', 'title-'.$size.'-'.$index),
                'party_name' => 'Parte sintética', 'document_number' => 'DOC-'.$index,
                'issue_date' => '2026-08-01', 'due_date' => '2026-08-15',
                'original_amount' => $amount, 'discount_amount' => '0.00',
                'addition_amount' => '0.00', 'total_amount' => $amount,
                'currency' => 'BRL', 'account_id' => 1, 'status' => 'OPEN',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('financial_titles')->insert($titles);
    }

    $titleIds = DB::table('financial_titles')
        ->select(['id', 'external_id', 'total_amount'])->orderBy('id')->get();
    foreach ($titleIds->chunk(500) as $chunk) {
        $installments = [];
        foreach ($chunk as $title) {
            $installments[] = [
                'financial_title_id' => $title->id, 'installment_number' => 1,
                'due_date' => '2026-08-15', 'amount' => (string) $title->total_amount,
                'status' => 'OPEN', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('title_installments')->insert($installments);
    }

    foreach (array_chunk(range(1, $size), 500) as $indexes) {
        $transactions = [];
        foreach ($indexes as $index) {
            $amount = Money::fromCents(10000 + $index);
            $transactions[] = [
                'account_id' => 1, 'source_system_id' => $sourceId, 'import_batch_id' => $batchId,
                'external_id' => 'HML-PERF-TX-'.$size.'-'.$index, 'identity_quality' => 'STRONG',
                'direction' => 'DEBIT', 'amount' => $amount, 'currency' => 'BRL',
                'transaction_date' => '2026-08-15', 'description_original' => 'Movimento sintético DOC-'.$index,
                'document_number' => 'DOC-'.$index, 'payload_hash' => hash('sha256', 'tx-'.$size.'-'.$index),
                'raw_hash' => hash('sha256', 'raw-'.$size.'-'.$index), 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('bank_transactions')->insert($transactions);
    }
    $seedMs = elapsedMilliseconds($seedStarted);

    $queryMetrics = [];
    $queryMetrics['bank_period_ms'] = measure(fn () => DB::table('bank_transactions')
        ->where('account_id', 1)->whereBetween('transaction_date', ['2026-08-01', '2026-08-31'])
        ->orderBy('transaction_date')->limit(100)->get());
    $queryMetrics['eligible_titles_ms'] = measure(fn () => DB::table('title_installments as ti')
        ->join('financial_titles as ft', 'ft.id', '=', 'ti.financial_title_id')
        ->where('ft.account_id', 1)->where('ft.status', '!=', 'CANCELLED')
        ->where('ti.status', '!=', 'CANCELLED')->orderBy('ti.due_date')->limit(100)->get());

    $matchingStarted = hrtime(true);
    $matching = app(ReconciliationMatchingEngine::class)->generate(
        $sessionId, 1, 'hml-perf-generate-'.$size,
    );
    $matchingMs = elapsedMilliseconds($matchingStarted);

    $queryMetrics['candidate_queue_ms'] = measure(fn () => DB::table('reconciliation_candidates')
        ->where('reconciliation_session_id', $sessionId)->where('status', 'PENDING')
        ->orderByDesc('score')->limit(100)->get());
    $queryMetrics['exception_queue_ms'] = measure(fn () => DB::table('reconciliation_exceptions')
        ->where('reconciliation_session_id', $sessionId)->where('status', 'OPEN')
        ->orderBy('type')->limit(100)->get());

    $prefix = DB::getTablePrefix();
    $explain = [];
    $statements = [
        'bank_period' => "SELECT id FROM {$prefix}bank_transactions WHERE account_id = 1 AND transaction_date BETWEEN '2026-08-01' AND '2026-08-31' ORDER BY transaction_date LIMIT 100",
        'eligible_titles' => "SELECT ti.id FROM {$prefix}title_installments ti INNER JOIN {$prefix}financial_titles ft ON ft.id = ti.financial_title_id WHERE ft.account_id = 1 AND ft.status <> 'CANCELLED' AND ti.status <> 'CANCELLED' ORDER BY ti.due_date LIMIT 100",
        'candidate_queue' => "SELECT id FROM {$prefix}reconciliation_candidates WHERE reconciliation_session_id = {$sessionId} AND status = 'PENDING' ORDER BY score DESC LIMIT 100",
        'exception_queue' => "SELECT id FROM {$prefix}reconciliation_exceptions WHERE reconciliation_session_id = {$sessionId} AND status = 'OPEN' ORDER BY type LIMIT 100",
        'transaction_allocations' => "SELECT rmt.bank_transaction_id, SUM(rmt.allocated_amount) FROM {$prefix}reconciliation_match_transactions rmt INNER JOIN {$prefix}reconciliation_matches rm ON rm.id = rmt.reconciliation_match_id WHERE rm.status = 'CONFIRMED' GROUP BY rmt.bank_transaction_id",
    ];
    foreach ($statements as $name => $sql) {
        $explain[$name] = array_map(static fn (object $row): array => (array) $row, DB::select('EXPLAIN '.$sql));
    }

    $report['datasets'][] = [
        'size' => $size, 'migration_ms' => $migrationMs, 'seed_ms' => $seedMs,
        'matching_ms' => $matchingMs, 'matching_result' => $matching,
        'query_metrics' => $queryMetrics, 'explain' => $explain,
        'note' => 'Tempos são observacionais, dependem do host e não constituem SLA.',
    ];
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

function elapsedMilliseconds(int $started): float
{
    return round((hrtime(true) - $started) / 1_000_000, 3);
}

function measure(callable $operation): float
{
    $started = hrtime(true);
    $operation();

    return elapsedMilliseconds($started);
}
