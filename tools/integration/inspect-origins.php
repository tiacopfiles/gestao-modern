<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Auditoria SOMENTE LEITURA dos sistemas de origem
|--------------------------------------------------------------------------
|
| Levanta estrutura, volumes e semântica real dos bancos `contas` e
| `contasareceber` sem executar uma única escrita. Ver OriginReader para a
| trava que garante isso.
|
| Não despeja dados pessoais: usa COUNT/GROUP BY/MIN/MAX e amostras mínimas.
|
*/

use App\Integration\OriginReader;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$host = getenv('ORIGIN_DB_HOST') ?: '192.168.0.220';
$port = (int) (getenv('ORIGIN_DB_PORT') ?: 3306);
$user = getenv('ORIGIN_DB_USER') ?: 'ROOT';
$pass = (string) getenv('ORIGIN_DB_PASSWORD');

$targets = [
    'contas' => 'Contas a Pagar (suposto)',
    'contasareceber' => 'Contas a Receber (suposto)',
];

foreach ($targets as $database => $label) {
    echo str_repeat('=', 78)."\n";
    echo "BANCO: {$database}   ({$label})\n";
    echo str_repeat('=', 78)."\n";

    try {
        $reader = new OriginReader($host, $port, $database, $user, $pass);
    } catch (Throwable $e) {
        echo '  ERRO ao conectar: '.$e->getMessage()."\n\n";

        continue;
    }

    echo '  servidor: '.$reader->scalar('SELECT VERSION()')."\n";

    // --- tabelas -----------------------------------------------------------
    $tables = $reader->select(
        'SELECT table_name AS n, table_rows AS r FROM information_schema.tables WHERE table_schema = ? ORDER BY table_name',
        [$database],
    );
    echo '  tabelas ('.count($tables)."):\n";
    foreach ($tables as $t) {
        printf("      %-28s ~%s linhas\n", $t['n'], $t['r'] ?? '?');
    }

    // --- estrutura de lancamentos -----------------------------------------
    echo "\n  COLUNAS DE `lancamentos`:\n";
    $columns = $reader->select(
        'SELECT column_name AS n, column_type AS t, is_nullable AS nul, column_default AS d
         FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ordinal_position',
        [$database, 'lancamentos'],
    );
    if ($columns === []) {
        echo "      (tabela lancamentos não existe)\n";
    }
    foreach ($columns as $c) {
        printf("      %-22s %-16s %s%s\n", $c['n'], $c['t'],
            $c['nul'] === 'YES' ? 'NULL' : 'NOT NULL',
            $c['d'] !== null ? ' default='.$c['d'] : '');
    }

    // --- volumes -----------------------------------------------------------
    if ($columns !== []) {
        $names = array_column($columns, 'n');
        $total = $reader->scalar('SELECT COUNT(*) FROM lancamentos');
        echo "\n  TOTAL DE LANÇAMENTOS: {$total}\n";

        if (in_array('situacao', $names, true)) {
            echo "\n  DISTRIBUIÇÃO POR `situacao`:\n";
            foreach ($reader->select(
                'SELECT situacao AS s, COUNT(*) AS c FROM lancamentos GROUP BY situacao ORDER BY c DESC',
            ) as $row) {
                printf("      situacao=%-6s %s registros\n", var_export($row['s'], true), $row['c']);
            }
        }

        if (in_array('data_pagamento', $names, true)) {
            $withDate = $reader->scalar(
                "SELECT COUNT(*) FROM lancamentos WHERE data_pagamento IS NOT NULL AND data_pagamento <> '0000-00-00'",
            );
            echo "\n  COM data_pagamento preenchida: {$withDate}\n";

            echo "  CRUZAMENTO situacao x data_pagamento:\n";
            foreach ($reader->select(
                "SELECT situacao AS s,
                        CASE WHEN data_pagamento IS NULL OR data_pagamento = '0000-00-00' THEN 'sem data' ELSE 'com data' END AS d,
                        COUNT(*) AS c
                 FROM lancamentos GROUP BY situacao, d ORDER BY situacao",
            ) as $row) {
                printf("      situacao=%-6s %-10s %s\n", var_export($row['s'], true), $row['d'], $row['c']);
            }
        }

        // intervalo de datas
        foreach (['data_emissao', 'data_vencimento', 'data_pagamento'] as $field) {
            if (in_array($field, $names, true)) {
                $r = $reader->select("SELECT MIN({$field}) AS mn, MAX({$field}) AS mx FROM lancamentos WHERE {$field} IS NOT NULL AND {$field} <> '0000-00-00'");
                printf("  %-18s de %s até %s\n", $field, $r[0]['mn'] ?? '-', $r[0]['mx'] ?? '-');
            }
        }

        // --- tabela `situacao` (o dicionário de status) --------------------
        $hasSituacaoTable = ! empty(array_filter($tables, fn ($t) => $t['n'] === 'situacao'));
        if ($hasSituacaoTable) {
            echo "\n  DICIONÁRIO `situacao`:\n";
            foreach ($reader->select('SELECT * FROM situacao ORDER BY 1') as $row) {
                echo '      '.json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
            }
        }

        // --- contas bancárias ---------------------------------------------
        $hasContas = ! empty(array_filter($tables, fn ($t) => $t['n'] === 'contas'));
        if ($hasContas) {
            echo "\n  CONTAS CADASTRADAS:\n";
            foreach ($reader->select('SELECT * FROM contas ORDER BY 1') as $row) {
                echo '      '.json_encode($row, JSON_UNESCAPED_UNICODE)."\n";
            }
        }

        // --- uso real das contas em lançamentos ---------------------------
        if (in_array('conta', $names, true)) {
            echo "\n  USO DE `conta` EM LANÇAMENTOS:\n";
            foreach ($reader->select('SELECT conta AS c, COUNT(*) AS n FROM lancamentos GROUP BY conta ORDER BY n DESC LIMIT 15') as $row) {
                printf("      conta=%-8s %s lançamentos\n", var_export($row['c'], true), $row['n']);
            }
        }
    }

    echo "\n";
}
