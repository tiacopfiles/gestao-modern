<?php

use App\Domain\Financial\Enums\FinancialTitleType;
use App\Http\Controllers\Api\V1\BankImportController;
use App\Http\Controllers\Api\V1\BankTransactionController;
use App\Http\Controllers\Api\V1\FinancialTitleController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([
        'integration.correlation',
        'integration.log',
        'integration.auth',
        'integration.rate',
    ])
    ->group(function (): void {
        Route::prefix('payables')->name('api.v1.payables.')->group(function (): void {
            Route::post('/', [FinancialTitleController::class, 'store'])
                ->middleware(['integration.scope:payables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Payable->value)
                ->name('store');
            Route::get('/{external_id}', [FinancialTitleController::class, 'show'])
                ->middleware('integration.scope:payables:read')
                ->defaults('financial_title_type', FinancialTitleType::Payable->value)
                ->name('show');
            Route::put('/{external_id}', [FinancialTitleController::class, 'update'])
                ->middleware(['integration.scope:payables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Payable->value)
                ->name('update');
            Route::post('/{external_id}/cancel', [FinancialTitleController::class, 'cancel'])
                ->middleware(['integration.scope:payables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Payable->value)
                ->name('cancel');
            // Realização informada pela origem (Contas a Pagar deu baixa).
            Route::post('/{external_id}/settlements', [FinancialTitleController::class, 'settle'])
                ->middleware(['integration.scope:payables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Payable->value)
                ->name('settlements.store');
        });

        Route::prefix('receivables')->name('api.v1.receivables.')->group(function (): void {
            Route::post('/', [FinancialTitleController::class, 'store'])
                ->middleware(['integration.scope:receivables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Receivable->value)
                ->name('store');
            Route::get('/{external_id}', [FinancialTitleController::class, 'show'])
                ->middleware('integration.scope:receivables:read')
                ->defaults('financial_title_type', FinancialTitleType::Receivable->value)
                ->name('show');
            Route::put('/{external_id}', [FinancialTitleController::class, 'update'])
                ->middleware(['integration.scope:receivables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Receivable->value)
                ->name('update');
            Route::post('/{external_id}/cancel', [FinancialTitleController::class, 'cancel'])
                ->middleware(['integration.scope:receivables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Receivable->value)
                ->name('cancel');
            // Realização informada pela origem (Contas a Receber deu baixa).
            Route::post('/{external_id}/settlements', [FinancialTitleController::class, 'settle'])
                ->middleware(['integration.scope:receivables:write', 'integration.idempotency'])
                ->defaults('financial_title_type', FinancialTitleType::Receivable->value)
                ->name('settlements.store');
        });

        Route::prefix('bank-transactions')->name('api.v1.bank-transactions.')->group(function (): void {
            Route::post('/', [BankTransactionController::class, 'store'])
                ->middleware(['integration.scope:bank-transactions:write', 'integration.idempotency'])
                ->name('store');
            Route::get('/{account_id}/{external_id}', [BankTransactionController::class, 'show'])
                ->whereNumber('account_id')
                ->middleware('integration.scope:bank-transactions:read')
                ->name('show');
        });

        Route::prefix('bank-imports')->name('api.v1.bank-imports.')->group(function (): void {
            Route::post('/ofx', [BankImportController::class, 'storeOfx'])
                ->middleware(['integration.scope:bank-imports:write', 'integration.idempotency:detached'])
                ->name('ofx.store');
            Route::get('/{batch}', [BankImportController::class, 'show'])
                ->whereNumber('batch')
                ->middleware('integration.scope:bank-imports:read')
                ->name('show');
            Route::get('/{batch}/items', [BankImportController::class, 'items'])
                ->whereNumber('batch')
                ->middleware('integration.scope:bank-imports:read')
                ->name('items');
        });
    });
