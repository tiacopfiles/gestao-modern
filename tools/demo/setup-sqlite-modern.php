<?php

declare(strict_types=1);

use App\Application\Financial\TitleIngestionService;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\Exceptions\ReconciliationRuleViolation;
use App\Models\BankTransaction;
use App\Models\ImportBatch;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Mesmos guards de tools/demo/setup-sqlite.php — este script é aditivo e roda depois dele.
$expectedDatabase = realpath(base_path('database/database.sqlite'));
$configuredDatabase = realpath((string) config('database.connections.sqlite.database'));

if (app()->environment() !== 'local') {
    throw new RuntimeException('ABORT: o setup de demonstração exige APP_ENV=local.');
}
if (config('database.default') !== 'sqlite') {
    throw new RuntimeException('ABORT: o setup de demonstração aceita somente SQLite.');
}
if ($expectedDatabase === false || $configuredDatabase === false || $expectedDatabase !== $configuredDatabase) {
    throw new RuntimeException('ABORT: o banco deve ser exatamente database/database.sqlite deste projeto.');
}
foreach (['avt_lancamentos', 'avt_recebimentos', 'avt_movimentos', 'avt_conciliacoes'] as $protected) {
    if (Schema::hasTable($protected)) {
        throw new RuntimeException("ABORT: tabela protegida encontrada: {$protected}.");
    }
}
if (! Schema::hasTable('contas') || DB::table('contas')->where('id', 900001)->doesntExist()) {
    throw new RuntimeException('ABORT: rode primeiro php tools/demo/setup-sqlite.php (conta demo 900001 não existe).');
}

$user = User::query()->where('username', 'demo@acop.local')->first();
if (! $user) {
    throw new RuntimeException('ABORT: usuário demo@acop.local não existe. Rode primeiro tools/demo/setup-sqlite.php.');
}

// Override apenas no processo deste script (não altera .env) — os serviços de
// domínio checam a flag em tempo de execução; a seed precisa dela ligada para criar
// a sessão. A demonstração via `php artisan serve` liga as flags no `.env` normalmente.
config([
    'reconciliation.v2_enabled' => true,
    'reconciliation.matching_enabled' => true,
    'reconciliation.closing_enabled' => true,
]);

$accountId = 900001;
$today = now()->startOfDay();
$correlationId = fn (): string => (string) Str::uuid();

DB::transaction(function () use ($user, $accountId, $today, $correlationId): void {
    /** @var TitleIngestionService $ingestion */
    $ingestion = app(TitleIngestionService::class);

    // Título a pagar totalmente conciliável 1:1 (score alto no matching assistido).
    $ingestion->ingest(new TitleIngestionData(
        sourceCode: 'API', externalId: 'DEMO-PAYABLE-001', type: FinancialTitleType::Payable,
        issueDate: $today->copy()->subDays(10)->toDateString(), dueDate: $today->copy()->subDays(1)->toDateString(),
        originalAmount: '1500.00', partyName: 'Fornecedor Delta — Sintético', documentNumber: 'NF-DEMO-001',
        accountId: $accountId, installmentCount: 1,
    ), $user->id, $correlationId());

    // Título a receber parcialmente conciliável (para demonstrar conciliação parcial).
    $ingestion->ingest(new TitleIngestionData(
        sourceCode: 'API', externalId: 'DEMO-RECEIVABLE-001', type: FinancialTitleType::Receivable,
        issueDate: $today->copy()->subDays(8)->toDateString(), dueDate: $today->copy()->addDays(2)->toDateString(),
        originalAmount: '3200.00', partyName: 'Cliente Horizonte — Sintético', documentNumber: 'FAT-DEMO-002',
        accountId: $accountId, installmentCount: 1,
    ), $user->id, $correlationId());

    // Título a pagar sem transação bancária correspondente (para gerar divergência NO_CANDIDATE).
    $ingestion->ingest(new TitleIngestionData(
        sourceCode: 'API', externalId: 'DEMO-PAYABLE-002', type: FinancialTitleType::Payable,
        issueDate: $today->copy()->subDays(5)->toDateString(), dueDate: $today->copy()->addDays(5)->toDateString(),
        originalAmount: '480.00', partyName: 'Fornecedor Prisma — Sintético', documentNumber: 'NF-DEMO-003',
        accountId: $accountId, installmentCount: 1,
    ), $user->id, $correlationId());

    $source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();
    $batch = ImportBatch::query()->firstOrCreate(
        ['account_id' => $accountId, 'channel' => 'API', 'correlation_id' => 'demo-bank-import-batch'],
        [
            'source_system_id' => $source->id, 'format' => 'CANONICAL_API', 'status' => 'COMPLETED',
            'total_items' => 3, 'imported_items' => 3, 'started_at' => now(), 'completed_at' => now(),
        ],
    );

    $transactions = [
        ['id' => 'DEMO-TX-001', 'direction' => 'DEBIT', 'amount' => '1500.00', 'desc' => 'Pagamento fornecedor sintético — coincide com DEMO-PAYABLE-001'],
        ['id' => 'DEMO-TX-002', 'direction' => 'CREDIT', 'amount' => '2000.00', 'desc' => 'Recebimento parcial sintético — parte de DEMO-RECEIVABLE-001'],
        ['id' => 'DEMO-TX-003', 'direction' => 'CREDIT', 'amount' => '75.50', 'desc' => 'Crédito sintético sem título correspondente'],
    ];
    foreach ($transactions as $item) {
        BankTransaction::query()->updateOrCreate(
            ['account_id' => $accountId, 'external_id' => $item['id']],
            [
                'source_system_id' => $source->id, 'import_batch_id' => $batch->id,
                'identity_quality' => 'STRONG', 'direction' => $item['direction'], 'amount' => $item['amount'],
                'currency' => 'BRL', 'transaction_date' => $today->copy()->subDay()->toDateString(),
                'description_original' => $item['desc'], 'payload_hash' => hash('sha256', 'demo-payload-'.$item['id']),
                'raw_hash' => hash('sha256', 'demo-raw-'.$item['id']),
            ],
        );
    }

    /** @var ReconciliationSessionService $sessions */
    $sessions = app(ReconciliationSessionService::class);
    $periodStart = $today->copy()->startOfMonth()->toDateString();
    $periodEnd = $today->copy()->endOfMonth()->toDateString();
    try {
        $sessions->create($accountId, $periodStart, $periodEnd, $user->id, $correlationId());
    } catch (ReconciliationRuleViolation $exception) {
        if ($exception->rule !== 'RECONCILIATION_SESSION_DUPLICATE') {
            throw $exception;
        }
        // Sessão do período já existe (execução anterior deste script) — segue sem recriar.
    }
});

echo "Dados modernos de demonstração prontos (conta {$accountId}, usuário demo@acop.local).\n";
echo "3 títulos, 1 lote de importação com 3 transações bancárias, 1 sessão de conciliação para o mês atual.\n";
echo "Ative RECONCILIATION_V2_ENABLED=true (e opcionalmente MATCHING/CLOSING) e acesse /reconciliacao-v2.\n";
