<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cenários de demonstração — Fases 1 a 6
|--------------------------------------------------------------------------
|
| Recria, do zero e de forma determinística, os dez cenários necessários para
| demonstrar o sistema ponta a ponta sem cadastrar nada à mão:
|
|   1. conta a pagar pendente
|   2. conta a receber pendente
|   3. transação bancária correspondente
|   4. match perfeito (1:1)
|   5. divergência aberta
|   6. divergência justificada
|   7. título conciliado
|   8. período pronto para fechar
|   9. período fechado
|  10. período reabrível
|
| Usa exclusivamente os serviços de aplicação das Fases 1–6 (nunca SQL direto
| para regras de negócio), então o que a demonstração mostra é o mesmo caminho
| que a aplicação percorre.
|
| Três sessões, porque os estados são mutuamente exclusivos por desenho: uma
| divergência ABERTA bloqueia o fechamento (política Governada), logo "tem
| divergência aberta" e "pronta para fechar" não podem ser a mesma sessão.
|
|   Sessão 1 — conta 900001, mês atual .......... em operação
|   Sessão 2 — conta 900002, mês anterior ....... pronta para fechar
|   Sessão 3 — conta 900002, dois meses atrás ... fechada e reabrível
|
| DESTRUTIVO, mas apenas sobre as tabelas MODERNAS deste SQLite local de
| demonstração. Nunca toca `avt_*`, nunca toca banco real, e preserva usuários,
| contas e os dados legados sintéticos criados por setup-sqlite.php.
|
*/

use App\Application\Financial\SettlementService;
use App\Application\Financial\TitleIngestionService;
use App\Application\Reconciliation\ManualReconciliationService;
use App\Application\Reconciliation\ReconciliationClosureService;
use App\Application\Reconciliation\ReconciliationExceptionService;
use App\Application\Reconciliation\ReconciliationMatchingEngine;
use App\Application\Reconciliation\ReconciliationSessionService;
use App\Domain\Financial\Enums\FinancialTitleType;
use App\Domain\Financial\TitleIngestionData;
use App\Domain\Reconciliation\ReconciliationTitleAllocationData;
use App\Domain\Reconciliation\ReconciliationTransactionAllocationData;
use App\Models\BankTransaction;
use App\Models\FinancialTitle;
use App\Models\ImportBatch;
use App\Models\ReconciliationException;
use App\Models\SourceSystem;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// ---------------------------------------------------------------------------
// Guards fail-closed — mesmos de setup-sqlite.php / setup-sqlite-modern.php
// ---------------------------------------------------------------------------
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

$user = User::query()->where('username', 'demo@acop.local')->first();
if (! $user) {
    throw new RuntimeException('ABORT: rode primeiro php tools/demo/setup-sqlite.php (usuário demo ausente).');
}
foreach ([900001, 900002] as $account) {
    if (DB::table('contas')->where('id', $account)->doesntExist()) {
        throw new RuntimeException("ABORT: conta de demonstração {$account} não existe. Rode tools/demo/setup-sqlite.php.");
    }
}

// As flags valem só neste processo: os serviços checam em tempo de execução e a
// seed precisa delas ligadas. Não altera o .env.
config([
    'reconciliation.v2_enabled' => true,
    'reconciliation.matching_enabled' => true,
    'reconciliation.closing_enabled' => true,
]);

$cid = fn (string $p): string => $p.'-'.Str::uuid();
$today = now()->startOfDay();

// ---------------------------------------------------------------------------
// Reset das tabelas modernas (ordem filha → pai por causa das FKs RESTRICT)
// ---------------------------------------------------------------------------
$modernTables = [
    'reconciliation_closure_metrics',
    'reconciliation_closure_exceptions',
    'reconciliation_closure_matches',
    'reconciliation_reopenings',
    'reconciliation_closures',
    'reconciliation_exceptions',
    'reconciliation_candidate_transactions',
    'reconciliation_candidate_titles',
    'reconciliation_candidates',
    'reconciliation_match_transactions',
    'reconciliation_match_titles',
    'reconciliation_matches',
    'reconciliation_sessions',
    'import_batch_items',
    'bank_transactions',
    'import_batches',
    'title_cancellations',
    'title_settlements',
    'title_installments',
    'financial_titles',
    'audit_events',
    'integration_requests',
];

DB::statement('PRAGMA foreign_keys = OFF');
foreach ($modernTables as $table) {
    if (Schema::hasTable($table)) {
        DB::table($table)->delete();
        DB::statement("DELETE FROM sqlite_sequence WHERE name = '{$table}'");
    }
}
DB::statement('PRAGMA foreign_keys = ON');
echo "Tabelas modernas zeradas (legado sintético e usuários preservados).\n\n";

$ingestion = app(TitleIngestionService::class);
$sessions = app(ReconciliationSessionService::class);
$manual = app(ManualReconciliationService::class);
$engine = app(ReconciliationMatchingEngine::class);
$exceptions = app(ReconciliationExceptionService::class);
$closures = app(ReconciliationClosureService::class);
$source = SourceSystem::query()->where('code', 'BANK_IMPORT')->firstOrFail();

/** Cria um lote e transações bancárias sintéticas dentro de um período. */
$makeBatch = function (int $accountId, string $label, array $items) use ($source): array {
    $batch = ImportBatch::query()->create([
        'source_system_id' => $source->id, 'account_id' => $accountId, 'channel' => 'API',
        'format' => 'CANONICAL_API', 'status' => 'COMPLETED',
        'total_items' => count($items), 'imported_items' => count($items),
        'correlation_id' => 'demo-batch-'.$label, 'started_at' => now(), 'completed_at' => now(),
    ]);

    $created = [];
    foreach ($items as $item) {
        $created[$item['id']] = BankTransaction::query()->create([
            'account_id' => $accountId, 'source_system_id' => $source->id, 'import_batch_id' => $batch->id,
            'external_id' => $item['id'], 'identity_quality' => 'STRONG',
            'direction' => $item['direction'], 'amount' => $item['amount'], 'currency' => 'BRL',
            'transaction_date' => $item['date'],
            'description_original' => $item['desc'],
            'document_number' => $item['doc'] ?? null,
            'payload_hash' => hash('sha256', 'demo-payload-'.$item['id']),
            'raw_hash' => hash('sha256', 'demo-raw-'.$item['id']),
        ]);
    }

    return $created;
};

/** Ingere um título e devolve o modelo com parcelas carregadas. */
$makeTitle = function (array $spec) use ($ingestion, $user, $cid): FinancialTitle {
    return $ingestion->ingest(new TitleIngestionData(
        sourceCode: 'API',
        externalId: $spec['ext'],
        type: $spec['type'],
        issueDate: $spec['issue'],
        dueDate: $spec['due'],
        originalAmount: $spec['amount'],
        partyName: $spec['party'],
        documentNumber: $spec['doc'],
        accountId: $spec['account'],
        installmentCount: 1,
    ), $user->id, $cid('demo-title'))->title->load('installments');
};

// ===========================================================================
// SESSÃO 1 — conta 900001, mês atual — "em operação"
// ===========================================================================
$p1Start = $today->copy()->startOfMonth();
$p1End = $today->copy()->endOfMonth();
$inP1 = $today->copy()->max($p1Start)->min($p1End)->toDateString();

$s1 = $sessions->create(900001, $p1Start->toDateString(), $p1End->toDateString(), $user->id, $cid('demo-session-1'));

$t1 = $makeTitle(['ext' => 'DEMO-PAG-001', 'type' => FinancialTitleType::Payable, 'issue' => $p1Start->toDateString(),
    'due' => $inP1, 'amount' => '1500.00', 'party' => 'Fornecedor Delta — Sintético', 'doc' => 'NF-9001', 'account' => 900001]);
$t2 = $makeTitle(['ext' => 'DEMO-REC-001', 'type' => FinancialTitleType::Receivable, 'issue' => $p1Start->toDateString(),
    'due' => $inP1, 'amount' => '3200.00', 'party' => 'Cliente Horizonte — Sintético', 'doc' => 'FAT-9002', 'account' => 900001]);
// CENÁRIO 1 — conta a pagar pendente, sem transação correspondente
$t3 = $makeTitle(['ext' => 'DEMO-PAG-002', 'type' => FinancialTitleType::Payable, 'issue' => $p1Start->toDateString(),
    'due' => $p1End->toDateString(), 'amount' => '480.00', 'party' => 'Fornecedor Prisma — Sintético', 'doc' => 'NF-9003', 'account' => 900001]);
// CENÁRIO 2 — conta a receber pendente, sem transação correspondente
$t4 = $makeTitle(['ext' => 'DEMO-REC-002', 'type' => FinancialTitleType::Receivable, 'issue' => $p1Start->toDateString(),
    'due' => $p1End->toDateString(), 'amount' => '910.00', 'party' => 'Cliente Aurora — Sintético', 'doc' => 'FAT-9004', 'account' => 900001]);
// Par exato deixado DE PROPÓSITO sem match manual, para o motor determinístico
// sugerir e a demonstração ter um candidato pendente para aceitar.
$t8 = $makeTitle(['ext' => 'DEMO-PAG-003', 'type' => FinancialTitleType::Payable, 'issue' => $p1Start->toDateString(),
    'due' => $inP1, 'amount' => '890.00', 'party' => 'Fornecedor Sirius — Sintético', 'doc' => 'NF-9005', 'account' => 900001]);

// CENÁRIO 3 — transações bancárias, uma delas exatamente igual ao título
$tx1 = $makeBatch(900001, 'sessao1', [
    ['id' => 'DEMO-TX-001', 'direction' => 'DEBIT', 'amount' => '1500.00', 'date' => $inP1, 'doc' => 'NF-9001',
        'desc' => 'Pagamento fornecedor sintético — coincide com DEMO-PAG-001'],
    ['id' => 'DEMO-TX-002', 'direction' => 'CREDIT', 'amount' => '2000.00', 'date' => $inP1, 'doc' => 'FAT-9002',
        'desc' => 'Recebimento parcial sintético — parte de DEMO-REC-001'],
    ['id' => 'DEMO-TX-003', 'direction' => 'CREDIT', 'amount' => '75.50', 'date' => $inP1,
        'desc' => 'Crédito sintético sem título correspondente'],
    ['id' => 'DEMO-TX-004', 'direction' => 'DEBIT', 'amount' => '333.33', 'date' => $inP1,
        'desc' => 'Débito sintético sem título correspondente'],
    ['id' => 'DEMO-TX-005', 'direction' => 'DEBIT', 'amount' => '890.00', 'date' => $inP1, 'doc' => 'NF-9005',
        'desc' => 'Pagamento sintético — par exato de DEMO-PAG-003, para o motor sugerir'],
]);

// CENÁRIO 4 e 7 — match perfeito 1:1, título fica conciliado
$manual->confirm(
    $s1->id,
    [new ReconciliationTitleAllocationData($t1->id, $t1->installments->first()->id, '1500.00')],
    [new ReconciliationTransactionAllocationData($tx1['DEMO-TX-001']->id, '1500.00')],
    $user->id,
    $cid('demo-match-perfeito'),
);

// Gera sugestões e divergências pelo motor determinístico
$engine->generate($s1->id, $user->id, $cid('demo-matching'));

// CENÁRIO 6 — uma divergência justificada (não bloqueia fechamento)
// CENÁRIO 5 — as demais continuam ABERTAS (bloqueiam fechamento, de propósito)
$abertas = ReconciliationException::query()
    ->where('reconciliation_session_id', $s1->id)
    ->where('status', 'OPEN')
    ->orderBy('id')
    ->get();
if ($abertas->isNotEmpty()) {
    $exceptions->justify(
        $s1->id,
        $abertas->first()->id,
        'Divergência sintética já analisada: crédito identificado no extrato, sem título correspondente no período.',
        $user->id,
        $cid('demo-justificativa'),
    );
}

// ===========================================================================
// SESSÃO 2 — conta 900002, mês anterior — "pronta para fechar"
// ===========================================================================
$p2Start = $today->copy()->subMonthNoOverflow()->startOfMonth();
$p2End = $today->copy()->subMonthNoOverflow()->endOfMonth();
$inP2 = $p2Start->copy()->addDays(9)->toDateString();

$s2 = $sessions->create(900002, $p2Start->toDateString(), $p2End->toDateString(), $user->id, $cid('demo-session-2'));

$t5 = $makeTitle(['ext' => 'DEMO-PAG-101', 'type' => FinancialTitleType::Payable, 'issue' => $p2Start->toDateString(),
    'due' => $inP2, 'amount' => '2750.00', 'party' => 'Fornecedor Órion — Sintético', 'doc' => 'NF-9101', 'account' => 900002]);
$t6 = $makeTitle(['ext' => 'DEMO-REC-101', 'type' => FinancialTitleType::Receivable, 'issue' => $p2Start->toDateString(),
    'due' => $inP2, 'amount' => '1180.00', 'party' => 'Cliente Vega — Sintético', 'doc' => 'FAT-9102', 'account' => 900002]);

$tx2 = $makeBatch(900002, 'sessao2', [
    ['id' => 'DEMO-TX-101', 'direction' => 'DEBIT', 'amount' => '2750.00', 'date' => $inP2, 'doc' => 'NF-9101',
        'desc' => 'Pagamento sintético do mês anterior — coincide com DEMO-PAG-101'],
    ['id' => 'DEMO-TX-102', 'direction' => 'CREDIT', 'amount' => '1180.00', 'date' => $inP2, 'doc' => 'FAT-9102',
        'desc' => 'Recebimento sintético do mês anterior — coincide com DEMO-REC-101'],
    ['id' => 'DEMO-TX-103', 'direction' => 'CREDIT', 'amount' => '12.90', 'date' => $inP2,
        'desc' => 'Tarifa sintética sem título — será justificada'],
]);

$manual->confirm($s2->id,
    [new ReconciliationTitleAllocationData($t5->id, $t5->installments->first()->id, '2750.00')],
    [new ReconciliationTransactionAllocationData($tx2['DEMO-TX-101']->id, '2750.00')],
    $user->id, $cid('demo-match-s2a'));
$manual->confirm($s2->id,
    [new ReconciliationTitleAllocationData($t6->id, $t6->installments->first()->id, '1180.00')],
    [new ReconciliationTransactionAllocationData($tx2['DEMO-TX-102']->id, '1180.00')],
    $user->id, $cid('demo-match-s2b'));

$engine->generate($s2->id, $user->id, $cid('demo-matching-s2'));

// CENÁRIO 8 — justifica TODAS as divergências, deixando a sessão sem impedimento
foreach (ReconciliationException::query()->where('reconciliation_session_id', $s2->id)->where('status', 'OPEN')->orderBy('id')->get() as $e) {
    $exceptions->justify($s2->id, $e->id,
        'Divergência sintética justificada para liberar o fechamento de demonstração (tarifa bancária sem título).',
        $user->id, $cid('demo-justificativa-s2'));
}

// ===========================================================================
// SESSÃO 3 — conta 900002, dois meses atrás — "fechada e reabrível"
// ===========================================================================
$p3Start = $today->copy()->subMonthsNoOverflow(2)->startOfMonth();
$p3End = $today->copy()->subMonthsNoOverflow(2)->endOfMonth();
$inP3 = $p3Start->copy()->addDays(6)->toDateString();

$s3 = $sessions->create(900002, $p3Start->toDateString(), $p3End->toDateString(), $user->id, $cid('demo-session-3'));

$t7 = $makeTitle(['ext' => 'DEMO-PAG-201', 'type' => FinancialTitleType::Payable, 'issue' => $p3Start->toDateString(),
    'due' => $inP3, 'amount' => '640.00', 'party' => 'Fornecedor Lyra — Sintético', 'doc' => 'NF-9201', 'account' => 900002]);

$tx3 = $makeBatch(900002, 'sessao3', [
    ['id' => 'DEMO-TX-201', 'direction' => 'DEBIT', 'amount' => '640.00', 'date' => $inP3, 'doc' => 'NF-9201',
        'desc' => 'Pagamento sintético já conciliado e fechado'],
]);

$manual->confirm($s3->id,
    [new ReconciliationTitleAllocationData($t7->id, $t7->installments->first()->id, '640.00')],
    [new ReconciliationTransactionAllocationData($tx3['DEMO-TX-201']->id, '640.00')],
    $user->id, $cid('demo-match-s3'));

// CENÁRIO 9 e 10 — período fechado de fato, com snapshot/hash/métricas, pronto para reabrir
$closure = $closures->close($s3->id, $user->id, $cid('demo-fechamento'));

// ===========================================================================
// SESSÃO 4 — conta 900002, mês retrasado — CENÁRIO DE EXTRATO
//
// Cenário canônico de validação do saldo corrido:
//   saldo inicial   R$ 10.000,00
//   pagamento       −  R$  1.000,00  → R$  9.000,00
//   recebimento     +  R$  2.500,00  → R$ 11.500,00
//
// Percorre o ciclo inteiro: título → realizado (pago/recebido) → fato bancário
// → conciliado. É o cenário para conferir a coluna de saldo na tela /extrato.
// ===========================================================================
$p4Start = $today->copy()->subMonthsNoOverflow(3)->startOfMonth();
$p4End = $today->copy()->subMonthsNoOverflow(3)->endOfMonth();
$d1 = $p4Start->copy()->addDays(4)->toDateString();
$d2 = $p4Start->copy()->addDays(9)->toDateString();

$s4 = $sessions->create(900002, $p4Start->toDateString(), $p4End->toDateString(), $user->id, $cid('demo-session-4'));

$pagar = $makeTitle(['ext' => 'DEMO-EXTRATO-PAG', 'type' => FinancialTitleType::Payable, 'issue' => $p4Start->toDateString(),
    'due' => $d1, 'amount' => '1000.00', 'party' => 'Fornecedor Atlas — Sintético', 'doc' => 'NF-EXT-01', 'account' => 900002]);
$receber = $makeTitle(['ext' => 'DEMO-EXTRATO-REC', 'type' => FinancialTitleType::Receivable, 'issue' => $p4Start->toDateString(),
    'due' => $d2, 'amount' => '2500.00', 'party' => 'Cliente Nova — Sintético', 'doc' => 'FAT-EXT-02', 'account' => 900002]);

// Realização: a origem informou que foi pago / recebido.
$settlements = app(SettlementService::class);
$settlements->settle($pagar->id, '1000.00', $d1, $pagar->installments->first()->id, actorId: $user->id);
$settlements->settle($receber->id, '2500.00', $d2, $receber->installments->first()->id, actorId: $user->id);

$tx4 = $makeBatch(900002, 'extrato', [
    ['id' => 'DEMO-EXT-TX-01', 'direction' => 'DEBIT', 'amount' => '1000.00', 'date' => $d1, 'doc' => 'NF-EXT-01',
        'desc' => 'Pagamento fornecedor Atlas'],
    ['id' => 'DEMO-EXT-TX-02', 'direction' => 'CREDIT', 'amount' => '2500.00', 'date' => $d2, 'doc' => 'FAT-EXT-02',
        'desc' => 'Recebimento cliente Nova'],
]);

// Conciliação: liga cada título ao fato bancário que o comprova.
$manual->confirm($s4->id,
    [new ReconciliationTitleAllocationData($pagar->id, $pagar->installments->first()->id, '1000.00')],
    [new ReconciliationTransactionAllocationData($tx4['DEMO-EXT-TX-01']->id, '1000.00')],
    $user->id, $cid('demo-match-ext-1'));
$manual->confirm($s4->id,
    [new ReconciliationTitleAllocationData($receber->id, $receber->installments->first()->id, '2500.00')],
    [new ReconciliationTransactionAllocationData($tx4['DEMO-EXT-TX-02']->id, '2500.00')],
    $user->id, $cid('demo-match-ext-2'));

// ---------------------------------------------------------------------------
echo "Cenários de demonstração prontos.\n\n";
printf("  Sessão #%d — conta 900001 — %s a %s — EM OPERAÇÃO\n", $s1->id, $p1Start->format('d/m/Y'), $p1End->format('d/m/Y'));
printf("      5 títulos, 5 transações, 1 match confirmado, %d candidato(s) pendente(s), %d divergência(s) aberta(s) + 1 justificada\n",
    DB::table('reconciliation_candidates')->where('reconciliation_session_id', $s1->id)->where('status', 'PENDING')->count(),
    DB::table('reconciliation_exceptions')->where('reconciliation_session_id', $s1->id)->where('status', 'OPEN')->count());
printf("  Sessão #%d — conta 900002 — %s a %s — PRONTA PARA FECHAR\n", $s2->id, $p2Start->format('d/m/Y'), $p2End->format('d/m/Y'));
echo "      2 títulos conciliados, divergências todas justificadas, sem impedimento\n";
printf("  Sessão #%d — conta 900002 — %s a %s — FECHADA (fechamento #%d, pronta para reabrir)\n", $s3->id, $p3Start->format('d/m/Y'), $p3End->format('d/m/Y'), $closure->id);
echo '      closure_hash '.substr($closure->closure_hash, 0, 16)."...\n";
printf("  Sessão #%d — conta 900002 — %s a %s — CENÁRIO DE EXTRATO\n", $s4->id, $p4Start->format('d/m/Y'), $p4End->format('d/m/Y'));
echo "      ciclo completo: título → realizado → fato bancário → conciliado\n";
printf("      confira em /extrato com conta 900002, período %s a %s e saldo inicial 10.000,00:\n",
    $p4Start->format('d/m/Y'), $p4End->format('d/m/Y'));
echo "        saldo inicial 10.000,00 · pagamento −1.000,00 → 9.000,00 · recebimento +2.500,00 → 11.500,00\n\n";
echo "Acesse com demo@acop.local / Demo@Acop2026.\n";
