<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Conferência ORIGEM × GESTÃO
|--------------------------------------------------------------------------
|
| Compara registro a registro e em totais. Somente leitura dos dois lados.
| Qualquer diferença é reportada; nenhuma é silenciada.
|
*/

use App\Domain\Financial\Money;
use App\Integration\OriginExtractor;
use App\Integration\OriginReader;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$from = $argv[1] ?? '2026-01-01';
$to = $argv[2] ?? '2026-12-31';

$host = getenv('ORIGIN_DB_HOST') ?: '192.168.0.220';
$port = (int) (getenv('ORIGIN_DB_PORT') ?: 3306);
$user = getenv('ORIGIN_DB_USER') ?: 'ROOT';
$pass = (string) getenv('ORIGIN_DB_PASSWORD');

$brl = fn (int $cents) => number_format((float) Money::fromCents($cents), 2, ',', '.');

$systems = [
    ['db' => 'contas', 'source' => 'LEGACY_PAYABLE', 'type' => 'PAYABLE', 'label' => 'CONTAS A PAGAR'],
    ['db' => 'contasareceber', 'source' => 'LEGACY_RECEIVABLE', 'type' => 'RECEIVABLE', 'label' => 'CONTAS A RECEBER'],
];

$divergences = [];

foreach ($systems as $system) {
    echo str_repeat('=', 74)."\n{$system['label']}\n".str_repeat('=', 74)."\n";

    $reader = new OriginReader($host, $port, $system['db'], $user, $pass);
    $extractor = new OriginExtractor($reader, $system['source'], $system['type']);
    $source = SourceSystem::query()->where('code', $system['source'])->firstOrFail();

    $rows = $extractor->fetch($from, $to);

    // ---- índice do Gestão -------------------------------------------------
    $gestao = [];
    FinancialTitle::query()
        ->where('source_system_id', $source->id)
        ->with('installments')
        ->chunk(2000, function ($chunk) use (&$gestao): void {
            foreach ($chunk as $t) {
                $gestao[(string) $t->external_id] = $t;
            }
        });

    echo '  origem (lidos) ......... '.count($rows)."\n";
    echo '  gestão (importados) .... '.count($gestao)."\n";

    $expected = [];
    $rejected = 0;
    foreach ($rows as $row) {
        $m = $extractor->map($row, []);
        if (! $m['ok']) {
            $rejected++;

            continue;
        }
        $expected[$m['payload']['external_id']] = $m;
    }
    echo '  esperados (mapeáveis) .. '.count($expected)."  (rejeitados: {$rejected})\n\n";

    // ---- comparação registro a registro -----------------------------------
    $checked = 0;
    $mismatch = ['ausente' => 0, 'valor' => 0, 'vencimento' => 0, 'emissao' => 0,
        'documento' => 0, 'parte' => 0, 'tipo' => 0, 'status' => 0, 'liquidacao' => 0];
    $samples = [];

    $originOpen = 0;
    $originSettled = 0;

    foreach ($expected as $externalId => $m) {
        $p = $m['payload'];
        $cents = $extractor->cents($p['original_amount']);
        $m['settlement'] !== null ? $originSettled += $cents : $originOpen += $cents;

        $t = $gestao[$externalId] ?? null;
        if (! $t) {
            $mismatch['ausente']++;
            $samples['ausente'][] = $externalId;

            continue;
        }
        $checked++;

        if (Money::toCents((string) $t->total_amount) !== $cents) {
            $mismatch['valor']++;
            $samples['valor'][] = "{$externalId}: origem {$p['original_amount']} × gestão {$t->total_amount}";
        }
        if ($t->due_date->toDateString() !== $p['due_date']) {
            $mismatch['vencimento']++;
            $samples['vencimento'][] = "{$externalId}: origem {$p['due_date']} × gestão ".$t->due_date->toDateString();
        }
        if ($t->issue_date->toDateString() !== $p['issue_date']) {
            $mismatch['emissao']++;
        }
        if ((string) $t->document_number !== (string) ($p['document_number'] ?? '')) {
            $mismatch['documento']++;
            $samples['documento'][] = "{$externalId}: origem '".($p['document_number'] ?? '')."' × gestão '{$t->document_number}'";
        }
        if ((string) $t->party_name !== (string) ($p['party']['name'] ?? '')) {
            $mismatch['parte']++;
        }
        if ($t->type->value !== $system['type']) {
            $mismatch['tipo']++;
        }

        // status: realizado na origem ⟺ liquidado no Gestão
        $shouldBeSettled = $m['settlement'] !== null;
        $isSettled = $t->remainingCents() === 0;
        if ($shouldBeSettled !== $isSettled) {
            $mismatch['status']++;
            $samples['status'][] = "{$externalId}: origem ".($shouldBeSettled ? 'REALIZADO' : 'ABERTO')
                .' × gestão '.($isSettled ? 'REALIZADO' : 'ABERTO');
        }

        if ($shouldBeSettled) {
            $s = $t->settlements()->first();
            if ($s && $s->settlement_date->toDateString() !== $m['settlement']['settlement_date']) {
                $mismatch['liquidacao']++;
                $samples['liquidacao'][] = "{$externalId}: origem {$m['settlement']['settlement_date']} × gestão ".$s->settlement_date->toDateString();
            }
        }
    }

    echo "  COMPARAÇÃO REGISTRO A REGISTRO ({$checked} conferidos):\n";
    $totalMismatch = 0;
    foreach ($mismatch as $field => $count) {
        printf("     %-14s %s\n", $field, $count);
        $totalMismatch += $count;
    }
    echo '     '.($totalMismatch === 0 ? '>>> ZERO DIVERGÊNCIAS <<<' : ">>> {$totalMismatch} DIVERGÊNCIAS <<<")."\n";

    foreach ($samples as $kind => $list) {
        echo "     exemplos [{$kind}]:\n";
        foreach (array_slice($list, 0, 3) as $s) {
            echo '        '.$s."\n";
        }
    }

    // ---- totais -----------------------------------------------------------
    $gTotal = 0;
    $gOpen = 0;
    $gSettled = 0;
    foreach ($gestao as $t) {
        $total = Money::toCents((string) $t->total_amount);
        $remaining = $t->remainingCents();
        $gTotal += $total;
        $gOpen += $remaining;
        $gSettled += $total - $remaining;
    }

    echo "\n  CONFERÊNCIA FINANCEIRA:\n";
    printf("     %-22s %14s   %14s   %s\n", '', 'ORIGEM', 'GESTÃO', 'DIFERENÇA');
    printf("     %-22s %14s   %14s   %s\n", 'registros',
        count($expected), count($gestao), count($expected) - count($gestao));
    printf("     %-22s %14s   %14s   R$ %s\n", 'total',
        $brl($originOpen + $originSettled), $brl($gTotal), $brl(($originOpen + $originSettled) - $gTotal));
    printf("     %-22s %14s   %14s   R$ %s\n", 'em aberto',
        $brl($originOpen), $brl($gOpen), $brl($originOpen - $gOpen));
    printf("     %-22s %14s   %14s   R$ %s\n", 'realizado',
        $brl($originSettled), $brl($gSettled), $brl($originSettled - $gSettled));

    if (($originOpen + $originSettled) !== $gTotal || $originOpen !== $gOpen || $originSettled !== $gSettled) {
        $divergences[] = $system['label'].': totais não fecham';
    }
    if ($totalMismatch > 0) {
        $divergences[] = $system['label'].": {$totalMismatch} divergências registro a registro";
    }

    echo "\n";
}

echo str_repeat('=', 74)."\n";
echo $divergences === []
    ? "RESULTADO: ZERO DIVERGÊNCIAS INEXPLICADAS\n"
    : "RESULTADO: DIVERGÊNCIAS ENCONTRADAS\n   - ".implode("\n   - ", $divergences)."\n";

echo "\nEXTRATO BANCÁRIO:\n";
printf("   bank_transactions ...... %s\n", DB::table('bank_transactions')->count());
printf("   reconciliation_matches . %s\n", DB::table('reconciliation_matches')->count());
echo "   (nenhum fato bancário real foi importado; nenhum match foi criado)\n";
