<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Importa os títulos reais da origem para um Gestão LOCAL ISOLADO
|--------------------------------------------------------------------------
|
| Direção única: ORIGENS → GESTÃO LOCAL. Nunca o contrário.
|
| A origem é lida por OriginReader (somente leitura, com trava). O destino é um
| SQLite dedicado a este teste — nunca o banco de desenvolvimento com os dados
| de demonstração, e nunca produção.
|
| Uso:
|   set DB_DATABASE=<caminho do sqlite isolado>
|   php tools/integration/import-to-gestao.php [de] [ate]
|
*/

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Integration\OriginExtractor;
use App\Integration\OriginReader;
use App\Models\Conta;
use App\Models\FinancialTitle;
use App\Models\SourceSystem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// --- guard de destino ------------------------------------------------------
// A direção é sempre ORIGENS → GESTÃO. Este guard existe para que uma execução
// distraída nunca escreva na origem, no banco de demonstração, nem no Gestão
// legado que já está publicado. Dois destinos são legítimos:
//   - SQLite dedicado ao teste local (nome contém 'integracao');
//   - banco próprio do Gestão em MySQL/MariaDB (nome contém 'gestao').
$conexao = (string) config('database.default');
$database = (string) config("database.connections.{$conexao}.database");
$alvo = mb_strtolower(basename($database));

// Bancos que este script jamais pode ter como destino, em nenhuma circunstância.
$proibidos = [
    'contas',                     // ORIGEM — contas a pagar
    'contasareceber',             // ORIGEM — contas a receber
    'contasareceber_homologacao', // cópia da origem
    'contasareceber_review_qa',   // cópia da origem
    'contas_agrocolitti',         // outro sistema
    'gestao',                     // Gestão legado já publicado no servidor
];

if (in_array($alvo, $proibidos, true)) {
    throw new RuntimeException(
        "ABORT: '{$alvo}' é banco de ORIGEM ou sistema já publicado. ".
        'O Gestão nunca escreve nesses bancos.'
    );
}

if (! str_contains($alvo, 'integracao') && ! str_contains($alvo, 'gestao')) {
    throw new RuntimeException(
        "ABORT: destino '{$database}' não parece ser um banco próprio do Gestão. ".
        "Esperado um nome contendo 'gestao' (servidor) ou 'integracao' (teste local)."
    );
}

echo "CONEXÃO: {$conexao}\n";

$from = $argv[1] ?? '2026-01-01';
$to = $argv[2] ?? '2026-12-31';

$host = getenv('ORIGIN_DB_HOST') ?: '192.168.0.220';
$port = (int) (getenv('ORIGIN_DB_PORT') ?: 3306);
$user = getenv('ORIGIN_DB_USER') ?: 'ROOT';
$pass = (string) getenv('ORIGIN_DB_PASSWORD');

echo "DESTINO: {$database}\n";
echo "PERÍODO: {$from} a {$to}\n\n";

$systems = [
    ['db' => 'contas', 'source' => 'LEGACY_PAYABLE', 'type' => FinancialTitleType::Payable, 'label' => 'CONTAS A PAGAR'],
    ['db' => 'contasareceber', 'source' => 'LEGACY_RECEIVABLE', 'type' => FinancialTitleType::Receivable, 'label' => 'CONTAS A RECEBER'],
];

$ingestion = app(TitleIngestionService::class);
$settlements = app(SettlementService::class);

// --- contas canônicas -------------------------------------------------------
// Os ids da origem colidem entre os dois bancos, então a conta do Gestão é
// criada a partir do NOME, unificando os dois sistemas.
if (! Schema::hasTable('contas')) {
    Schema::create('contas', function ($table): void {
        $table->increments('id');
        $table->string('nome');
        $table->string('banco', 120)->nullable();
        $table->string('nome_detalhado')->nullable();
        $table->timestamps();
        $table->softDeletes();
    });
}

$canonical = [];
foreach ($systems as $system) {
    $reader = new OriginReader($host, $port, $system['db'], $user, $pass);
    $extractor = new OriginExtractor($reader, $system['source'], $system['type']->value);
    foreach (array_keys($extractor->accounts()) as $name) {
        if (isset($canonical[$name])) {
            continue;
        }
        $existing = DB::table('contas')->where('nome', $name)->first();
        $canonical[$name] = $existing
            ? (int) $existing->id
            : (int) DB::table('contas')->insertGetId([
                'nome' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
    }
}
echo 'contas canônicas: '.count($canonical)."\n\n";

$report = [];

foreach ($systems as $system) {
    echo str_repeat('=', 70)."\n{$system['label']}\n".str_repeat('=', 70)."\n";

    $reader = new OriginReader($host, $port, $system['db'], $user, $pass);
    $extractor = new OriginExtractor($reader, $system['source'], $system['type']->value);
    $source = SourceSystem::query()->where('code', $system['source'])->firstOrFail();

    $rows = $extractor->fetch($from, $to);
    $stats = ['lidos' => count($rows), 'criados' => 0, 'reconhecidos' => 0, 'liquidados' => 0,
        'rejeitados' => 0, 'erros' => 0];
    $errors = [];
    $started = microtime(true);

    foreach ($rows as $index => $row) {
        $mapped = $extractor->map($row, array_map('strval', $canonical));
        if (! $mapped['ok']) {
            $stats['rejeitados']++;

            continue;
        }

        $p = $mapped['payload'];

        try {
            $before = FinancialTitle::query()
                ->where('source_system_id', $source->id)
                ->where('external_id', $p['external_id'])
                ->exists();

            $result = $ingestion->ingest(new TitleIngestionData(
                sourceCode: $system['source'],
                externalId: $p['external_id'],
                type: $system['type'],
                issueDate: $p['issue_date'],
                dueDate: $p['due_date'],
                originalAmount: $p['original_amount'],
                discountAmount: $p['discount_amount'],
                additionAmount: $p['addition_amount'],
                partyType: $p['party']['type'] ?? null,
                partyName: $p['party']['name'] ?? null,
                documentNumber: $p['document_number'] ?? null,
                accountId: $p['account_id'] ?? null,
                currency: 'BRL',
                notes: $p['notes'] ?? null,
                installmentCount: 1,
            ), null, 'import-'.$system['source'].'-'.$p['external_id']);

            $before ? $stats['reconhecidos']++ : $stats['criados']++;

            if ($mapped['settlement'] !== null) {
                $title = $result->title->load('installments');
                if ($title->remainingCents() > 0) {
                    $settlements->settle(
                        titleId: $title->id,
                        amount: $mapped['settlement']['amount'],
                        settlementDate: $mapped['settlement']['settlement_date'],
                        installmentId: $title->installments->first()?->id,
                        sourceSystemId: $source->id,
                        externalId: 'baixa-'.$p['external_id'],
                    );
                    $stats['liquidados']++;
                }
            }
        } catch (Throwable $e) {
            $stats['erros']++;
            $key = substr($e->getMessage(), 0, 90);
            $errors[$key] = ($errors[$key] ?? 0) + 1;
        }

        if (($index + 1) % 1000 === 0) {
            printf("   ... %s/%s\n", $index + 1, count($rows));
        }
    }

    $stats['segundos'] = round(microtime(true) - $started, 1);
    $report[$system['label']] = ['stats' => $stats, 'errors' => $errors];

    foreach ($stats as $k => $v) {
        printf("   %-14s %s\n", $k, $v);
    }
    if ($errors !== []) {
        echo "   ERROS:\n";
        foreach ($errors as $msg => $count) {
            printf("      (%s) %s\n", $count, $msg);
        }
    }
    echo "\n";
}

echo str_repeat('=', 70)."\nESTADO FINAL DO GESTÃO LOCAL\n".str_repeat('=', 70)."\n";
printf("   financial_titles ... %s\n", DB::table('financial_titles')->count());
printf("   title_settlements .. %s\n", DB::table('title_settlements')->count());
printf("   contas ............. %s\n", DB::table('contas')->count());
printf("   bank_transactions .. %s  (nenhum extrato real foi importado)\n", DB::table('bank_transactions')->count());
printf("   reconciliation_matches %s  (nenhum match criado)\n", DB::table('reconciliation_matches')->count());
