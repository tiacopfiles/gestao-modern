<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DRY-RUN da integração — nenhuma escrita, em lugar nenhum
|--------------------------------------------------------------------------
|
| Lê a origem, aplica o mapeamento e relata o que SERIA importado, incluindo
| toda rejeição e seu motivo. Não escreve na origem nem no Gestão.
|
| Uso: php tools/integration/dry-run.php [2026-01-01] [2026-12-31]
|
*/

use App\Integration\OriginExtractor;
use App\Integration\OriginReader;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$from = $argv[1] ?? '2026-01-01';
$to = $argv[2] ?? '2026-12-31';

$host = getenv('ORIGIN_DB_HOST') ?: '192.168.0.220';
$port = (int) (getenv('ORIGIN_DB_PORT') ?: 3306);
$user = getenv('ORIGIN_DB_USER') ?: 'ROOT';
$pass = (string) getenv('ORIGIN_DB_PASSWORD');

$systems = [
    ['db' => 'contas', 'source' => 'LEGACY_PAYABLE', 'type' => 'PAYABLE', 'label' => 'CONTAS A PAGAR'],
    ['db' => 'contasareceber', 'source' => 'LEGACY_RECEIVABLE', 'type' => 'RECEIVABLE', 'label' => 'CONTAS A RECEBER'],
];

// Registro canônico de contas: união dos nomes dos dois bancos, porque os ids
// da origem colidem entre si (o mesmo 'Marco' é 1 e 16).
$canonicalAccounts = [];
$nextAccountId = 1;

echo "DRY-RUN — período de vencimento {$from} a {$to}\n";
echo "Nenhuma escrita será feita. Origem em modo somente leitura.\n\n";

$grand = ['lidos' => 0, 'importaveis' => 0, 'rejeitados' => 0];

foreach ($systems as $system) {
    echo str_repeat('=', 78)."\n{$system['label']}  (banco `{$system['db']}`)\n".str_repeat('=', 78)."\n";

    $reader = new OriginReader($host, $port, $system['db'], $user, $pass);
    $extractor = new OriginExtractor($reader, $system['source'], $system['type']);

    foreach (array_keys($extractor->accounts()) as $name) {
        if (! isset($canonicalAccounts[$name])) {
            $canonicalAccounts[$name] = $nextAccountId++;
        }
    }

    $rows = $extractor->fetch($from, $to);
    echo '  registros lidos: '.count($rows)."\n";

    $ok = 0;
    $rejected = [];
    $flags = ['pago_sem_data' => 0, 'emissao_ajustada' => 0, 'conta_nao_mapeada' => 0];
    $totalCents = 0;
    $openCents = 0;
    $settledCents = 0;
    $settlements = 0;
    $samples = [];

    foreach ($rows as $row) {
        $result = $extractor->map($row, array_map('strval', $canonicalAccounts));

        if (! $result['ok']) {
            $rejected[$result['reason']] = ($rejected[$result['reason']] ?? 0) + 1;

            continue;
        }

        $ok++;
        $cents = $extractor->cents($result['payload']['original_amount']);
        $totalCents += $cents;

        if ($result['settlement'] !== null) {
            $settlements++;
            $settledCents += $cents;
        } else {
            $openCents += $cents;
        }

        foreach (array_keys($flags) as $flag) {
            if (! empty($result['meta'][$flag])) {
                $flags[$flag]++;
            }
        }

        if (count($samples) < 3) {
            $samples[] = $result;
        }
    }

    echo "\n  IMPORTÁVEIS: {$ok}\n";
    printf("     total ............ R$ %s\n", number_format((float) $extractor->money($totalCents), 2, ',', '.'));
    printf("     em aberto ........ R$ %s\n", number_format((float) $extractor->money($openCents), 2, ',', '.'));
    printf("     realizado ........ R$ %s  (%s liquidações)\n",
        number_format((float) $extractor->money($settledCents), 2, ',', '.'), $settlements);

    echo "\n  REJEITADOS: ".array_sum($rejected)."\n";
    foreach ($rejected as $reason => $count) {
        printf("     %-26s %s\n", $reason, $count);
    }

    echo "\n  OBSERVAÇÕES (importados, mas com ressalva):\n";
    foreach ($flags as $flag => $count) {
        printf("     %-26s %s\n", $flag, $count);
    }

    echo "\n  AMOSTRA DO MAPEAMENTO:\n";
    foreach ($samples as $s) {
        $p = $s['payload'];
        printf("     id=%-8s doc=%-16s venc=%s  R$ %-12s  %s\n",
            $p['external_id'],
            substr((string) ($p['document_number'] ?? '-'), 0, 16),
            $p['due_date'],
            $p['original_amount'],
            $s['settlement'] ? 'REALIZADO em '.$s['settlement']['settlement_date'] : 'EM ABERTO');
    }

    $grand['lidos'] += count($rows);
    $grand['importaveis'] += $ok;
    $grand['rejeitados'] += array_sum($rejected);
    echo "\n";
}

echo str_repeat('=', 78)."\nRESUMO\n".str_repeat('=', 78)."\n";
printf("  lidos ........ %s\n", $grand['lidos']);
printf("  importáveis .. %s\n", $grand['importaveis']);
printf("  rejeitados ... %s\n", $grand['rejeitados']);
printf("  contas canônicas (união dos dois bancos): %s\n", count($canonicalAccounts));
