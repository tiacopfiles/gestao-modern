<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankLedgerController;
use App\Http\Controllers\BankStatementController;
use App\Http\Controllers\CashFlowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FinancialController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ManualMovementController;
use App\Http\Controllers\MovementController;
use App\Http\Controllers\PeriodStatementController;
use App\Http\Controllers\ReconciliationClosureController;
use App\Http\Controllers\ReconciliationController;
use App\Http\Controllers\ReconciliationMatchingController;
use App\Http\Controllers\ReconciliationV2Controller;
use App\Http\Controllers\RegistryCrudController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\TitleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    // Sincronizacao com as origens legadas (somente leitura na origem)
    Route::post('/sincronizar', [SyncController::class, 'store'])->name('sync.store');

    // Quarentena: titulos que a origem tenta alterar contra a regra de negocio.
    // Nao e falha tecnica — e divergencia entre duas verdades, e precisa de
    // decisao humana em vez de um alarme que toca a cada 5 minutos.
    Route::get('/sincronizacao/quarentena', [SyncController::class, 'conflicts'])->name('sync.conflicts');
    Route::post('/sincronizacao/quarentena/{conflict}/resolver', [SyncController::class, 'resolveConflict'])
        ->middleware('can:reconciliation:manage')->name('sync.conflicts.resolve');

    // Movimento do periodo: entradas, saidas e saldo corrido de uma conta,
    // montado a partir das liquidacoes reais. Gerar nao altera nenhum titulo.
    // Movimentos manuais: PIX, tarifa, rendimento, ajuste. Ficam sob a mesma
    // URL da conciliação porque é onde eles aparecem — a pessoa que precisa
    // lançar um PIX está olhando o movimento do período. Criar um movimento
    // manual não altera título, não cria baixa e não escreve nas origens.
    Route::prefix('conciliacao/movimentos')->name('manual-movements.')
        ->middleware('reconciliation.v2')
        ->group(function (): void {
            Route::get('/', [ManualMovementController::class, 'index'])
                ->middleware('can:reconciliation:view')->name('index');
            Route::get('/novo', [ManualMovementController::class, 'create'])
                ->middleware('can:reconciliation:manage')->name('create');
            Route::post('/', [ManualMovementController::class, 'store'])
                ->middleware('can:reconciliation:manage')->name('store');
            Route::get('/{movimento}/editar', [ManualMovementController::class, 'edit'])
                ->middleware('can:reconciliation:manage')->name('edit');
            Route::put('/{movimento}', [ManualMovementController::class, 'update'])
                ->middleware('can:reconciliation:manage')->name('update');
            Route::delete('/{movimento}', [ManualMovementController::class, 'destroy'])
                ->middleware('can:reconciliation:manage')->name('destroy');
        });

    Route::prefix('conciliacao')->name('period-statements.')
        ->middleware('reconciliation.v2')
        ->group(function (): void {
            Route::get('/', [PeriodStatementController::class, 'index'])
                ->middleware('can:reconciliation:view')->name('index');
            Route::get('/nova', [PeriodStatementController::class, 'create'])
                ->middleware('can:reconciliation:view')->name('create');
            // Cadastrar a conta bancária da empresa sem sair da tela de abertura.
            Route::post('/bancos', [PeriodStatementController::class, 'storeBankAccount'])
                ->middleware('can:reconciliation:manage')->name('bank-accounts.store');
            Route::post('/', [PeriodStatementController::class, 'store'])
                ->middleware('can:reconciliation:manage')->name('store');
            // Baixar leva o dado para fora do sistema: exige `export`, nao `view`.
            Route::get('/{periodStatement}/planilha', [PeriodStatementController::class, 'export'])
                ->middleware('can:reconciliation:export')->name('export');
            Route::get('/{periodStatement}', [PeriodStatementController::class, 'show'])
                ->middleware('can:reconciliation:view')->name('show');
            Route::post('/{periodStatement}/atualizar', [PeriodStatementController::class, 'refresh'])
                ->middleware('can:reconciliation:manage')->name('refresh');
            Route::post('/{periodStatement}/fechar', [PeriodStatementController::class, 'close'])
                ->middleware('can:reconciliation:manage')->name('close');
            // Tirar/devolver uma linha que não passou por esta conta bancária.
            Route::post('/{periodStatement}/linhas/{line}/remover', [PeriodStatementController::class, 'excludeLine'])
                ->middleware('can:reconciliation:manage')->name('lines.exclude');
            Route::post('/{periodStatement}/removidas/{exclusion}/devolver', [PeriodStatementController::class, 'restoreLine'])
                ->middleware('can:reconciliation:manage')->name('lines.restore');
            // Reordenar à mão as linhas de um dia (arrastar e soltar).
            Route::post('/{periodStatement}/linhas/reordenar', [PeriodStatementController::class, 'reorderLines'])
                ->middleware('can:reconciliation:manage')->name('lines.reorder');
            Route::delete('/{periodStatement}', [PeriodStatementController::class, 'destroy'])
                ->middleware('can:reconciliation:manage')->name('destroy');
        });

    Route::get('/contas-a-pagar', [FinancialController::class, 'payables'])->name('payables.index');
    Route::get('/contas-a-pagar/novo', [FinancialController::class, 'createPayable'])->name('payables.create')->middleware('can:payments');
    Route::post('/contas-a-pagar', [FinancialController::class, 'storePayable'])->name('payables.store')->middleware('can:payments');
    Route::get('/contas-a-pagar/{lancamento}', [FinancialController::class, 'payable'])->name('payables.show');
    Route::get('/contas-a-pagar/{lancamento}/editar', [FinancialController::class, 'editPayable'])->name('payables.edit')->middleware('can:payments');
    Route::put('/contas-a-pagar/{lancamento}', [FinancialController::class, 'updatePayable'])->name('payables.update')->middleware('can:payments');
    Route::delete('/contas-a-pagar/{lancamento}', [FinancialController::class, 'destroyPayable'])->name('payables.destroy')->middleware('can:payments');
    Route::post('/contas-a-pagar/{lancamento}/parcelas', [FinancialController::class, 'installmentsPayable'])->name('payables.installments')->middleware('can:payments');

    Route::get('/contas-a-receber', [FinancialController::class, 'receivables'])->name('receivables.index');
    Route::get('/contas-a-receber/novo', [FinancialController::class, 'createReceivable'])->name('receivables.create')->middleware('can:payments');
    Route::post('/contas-a-receber', [FinancialController::class, 'storeReceivable'])->name('receivables.store')->middleware('can:payments');
    Route::get('/contas-a-receber/{recebimento}', [FinancialController::class, 'receivable'])->name('receivables.show');
    Route::get('/contas-a-receber/{recebimento}/editar', [FinancialController::class, 'editReceivable'])->name('receivables.edit')->middleware('can:payments');
    Route::put('/contas-a-receber/{recebimento}', [FinancialController::class, 'updateReceivable'])->name('receivables.update')->middleware('can:payments');
    Route::delete('/contas-a-receber/{recebimento}', [FinancialController::class, 'destroyReceivable'])->name('receivables.destroy')->middleware('can:payments');
    Route::post('/contas-a-receber/{recebimento}/parcelas', [FinancialController::class, 'installmentsReceivable'])->name('receivables.installments')->middleware('can:payments');

    Route::resource('movimentos', MovementController::class)->parameters(['movimentos' => 'movimento'])->names('movements')->middlewareFor(['create', 'store', 'edit', 'update', 'destroy'], 'can:payments');
    Route::get('/conciliacoes', [ReconciliationController::class, 'index'])->name('reconciliations.index');
    Route::get('/conciliacoes/nova', [ReconciliationController::class, 'create'])->name('reconciliations.create')->middleware('can:payments');
    Route::post('/conciliacoes', [ReconciliationController::class, 'store'])->name('reconciliations.store')->middleware('can:payments');
    Route::get('/conciliacoes/{conciliacao}', [ReconciliationController::class, 'show'])->name('reconciliations.show');
    Route::get('/conciliacoes/{conciliacao}/editar', [ReconciliationController::class, 'edit'])->name('reconciliations.edit')->middleware('can:payments');
    Route::put('/conciliacoes/{conciliacao}', [ReconciliationController::class, 'update'])->name('reconciliations.update')->middleware('can:payments');
    Route::patch('/conciliacoes/{conciliacao}/status', [ReconciliationController::class, 'status'])->name('reconciliations.status')->middleware('can:payments');
    Route::delete('/conciliacoes/{conciliacao}', [ReconciliationController::class, 'destroy'])->name('reconciliations.destroy')->middleware('can:payments');
    Route::get('/conciliacoes/{conciliacao}/exportar', [ReconciliationController::class, 'export'])->name('reconciliations.export');
    Route::get('/fluxo-de-caixa', [CashFlowController::class, 'index'])->name('cash-flow.index');

    Route::prefix('reconciliacao-v2')
        ->name('reconciliation-v2.')
        ->middleware('reconciliation.v2')
        ->group(function (): void {
            Route::get('/', [ReconciliationV2Controller::class, 'index'])
                ->middleware('can:reconciliation:view')->name('index');
            Route::get('/nova', [ReconciliationV2Controller::class, 'create'])
                ->middleware('can:reconciliation:manage')->name('create');
            Route::post('/', [ReconciliationV2Controller::class, 'store'])
                ->middleware('can:reconciliation:manage')->name('store');
            Route::get('/sessoes/{session}', [ReconciliationV2Controller::class, 'show'])
                ->middleware('can:reconciliation:view')->name('show');
            Route::post('/sessoes/{session}/matches', [ReconciliationV2Controller::class, 'storeMatch'])
                ->middleware('can:reconciliation:manage')->name('matches.store');
            Route::get('/sessoes/{session}/matches/{match}', [ReconciliationV2Controller::class, 'showMatch'])
                ->middleware('can:reconciliation:view')->name('matches.show');
            Route::post('/sessoes/{session}/matches/{match}/void', [ReconciliationV2Controller::class, 'voidMatch'])
                ->middleware('can:reconciliation:manage')->name('matches.void');
            Route::middleware('reconciliation.matching')->group(function (): void {
                Route::post('/sessoes/{session}/matching/gerar', [ReconciliationMatchingController::class, 'generate'])
                    ->middleware('can:reconciliation:manage')->name('matching.generate');
                Route::get('/sessoes/{session}/candidatos/{candidate}', [ReconciliationMatchingController::class, 'showCandidate'])
                    ->middleware('can:reconciliation:view')->name('candidates.show');
                Route::post('/sessoes/{session}/candidatos/{candidate}/aceitar', [ReconciliationMatchingController::class, 'accept'])
                    ->middleware('can:reconciliation:manage')->name('candidates.accept');
                Route::post('/sessoes/{session}/candidatos/{candidate}/rejeitar', [ReconciliationMatchingController::class, 'reject'])
                    ->middleware('can:reconciliation:manage')->name('candidates.reject');
                Route::get('/sessoes/{session}/divergencias/{exception}', [ReconciliationMatchingController::class, 'showException'])
                    ->middleware('can:reconciliation:view')->name('exceptions.show');
                Route::post('/sessoes/{session}/divergencias/{exception}/justificar', [ReconciliationMatchingController::class, 'justify'])
                    ->middleware('can:reconciliation:manage')->name('exceptions.justify');
            });
            Route::middleware('reconciliation.closing')->group(function (): void {
                Route::get('/sessoes/{session}/fechamento/novo', [ReconciliationClosureController::class, 'create'])
                    ->middleware('can:reconciliation:close')->name('closure.create');
                Route::post('/sessoes/{session}/fechamento', [ReconciliationClosureController::class, 'store'])
                    ->middleware('can:reconciliation:close')->name('closure.store');
                Route::get('/sessoes/{session}/fechamentos', [ReconciliationClosureController::class, 'history'])
                    ->middleware('can:reconciliation:view')->name('closure.history');
                Route::get('/sessoes/{session}/fechamentos/{closure}', [ReconciliationClosureController::class, 'show'])
                    ->middleware('can:reconciliation:view')->name('closure.show');
                Route::post('/sessoes/{session}/fechamentos/{closure}/reabrir', [ReconciliationClosureController::class, 'reopen'])
                    ->middleware('can:reconciliation:reopen')->name('closure.reopen');
            });
        });

    // Títulos do núcleo moderno e extrato com saldo corrido. Ficam atrás da mesma
    // flag da conciliação v2 porque são o fluxo que alimenta e explica a
    // conciliação — com a flag desligada, nada muda para o usuário do legado.
    Route::middleware('reconciliation.v2')->group(function (): void {
        Route::get('/titulos', [TitleController::class, 'index'])
            ->middleware('can:reconciliation:view')->name('titles.index');
        Route::get('/titulos/{title}', [TitleController::class, 'show'])
            ->middleware('can:reconciliation:view')->name('titles.show');
        Route::post('/titulos/{title}/liquidar', [TitleController::class, 'settle'])
            ->middleware('can:payments')->name('titles.settle');

        Route::get('/extrato', [BankLedgerController::class, 'index'])
            ->middleware('can:reconciliation:view')->name('ledger.index');
        Route::get('/extrato/exportar', [BankLedgerController::class, 'export'])
            ->middleware('can:reconciliation:view')->name('ledger.export');
    });

    // Extratos bancários (Fase 3) — a ingestão já existia, mas somente via API.
    // Fica atrás da mesma flag da conciliação v2 porque é o fluxo que consome
    // esses fatos; com a flag desligada o menu não muda de comportamento.
    Route::prefix('extratos')->name('banking.')
        ->middleware('reconciliation.v2')
        ->group(function (): void {
            Route::get('/', [BankStatementController::class, 'index'])
                ->middleware('can:reconciliation:view')->name('index');
            Route::get('/importar', [BankStatementController::class, 'create'])
                ->middleware('can:reconciliation:manage')->name('create');
            Route::post('/importar', [BankStatementController::class, 'store'])
                ->middleware('can:reconciliation:manage')->name('store');
            Route::get('/lotes/{batch}', [BankStatementController::class, 'showBatch'])
                ->middleware('can:reconciliation:view')->name('batches.show');
        });

    Route::prefix('cadastros')->middleware('can:commercial')->group(function (): void {
        Route::get('/{kind}', [RegistryCrudController::class, 'index'])->name('registries.index');
        Route::get('/{kind}/novo', [RegistryCrudController::class, 'create'])->name('registries.create');
        Route::post('/{kind}', [RegistryCrudController::class, 'store'])->name('registries.store');
        Route::get('/{kind}/{id}/editar', [RegistryCrudController::class, 'edit'])->name('registries.edit');
        Route::put('/{kind}/{id}', [RegistryCrudController::class, 'update'])->name('registries.update');
        Route::delete('/{kind}/{id}', [RegistryCrudController::class, 'destroy'])->name('registries.destroy');
    });
    Route::redirect('/clientes', '/cadastros/clientes')->name('clients.index');
    Route::redirect('/fornecedores', '/cadastros/fornecedores')->name('suppliers.index');
    Route::redirect('/contas', '/cadastros/contas')->name('accounts.index');

    Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user'])->names('users')->except('show')->middleware('can:commercial');
    Route::get('/auditoria', [AuditController::class, 'index'])->name('audit.index')->middleware('can:commercial');
    Route::post('/documentos/{entity}/{id}', [DocumentController::class, 'store'])->name('documents.store')->middleware('can:payments');
    Route::get('/documentos/{documento}', [DocumentController::class, 'download'])->name('documents.download');
    Route::delete('/documentos/{documento}', [DocumentController::class, 'destroy'])->name('documents.destroy')->middleware('can:payments');
});
