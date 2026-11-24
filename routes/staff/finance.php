<?php

declare(strict_types=1);

use App\Livewire\Staff\Finance\FinanceIndex;
use App\Livewire\Staff\Finance\GatewaySettings;
use App\Livewire\Staff\Finance\IndicatorsPage;
use App\Livewire\Staff\Finance\InvoiceShow;
use Illuminate\Support\Facades\Route;

/*
| Financeiro (equipe do distribuidor). Já dentro de auth, verified,
| context:staff e two-factor (routes/web.php). As telas exigem
| finance.view; as operações são autorizadas pelas Actions (finance.manage).
*/

Route::middleware('permission:finance.view')->prefix('financeiro')->name('finance.')->group(function (): void {
    Route::livewire('/', FinanceIndex::class)->name('index');
    Route::livewire('/faturas/{invoice}', InvoiceShow::class)->whereUuid('invoice')->name('invoices.show');
    Route::livewire('/gateway', GatewaySettings::class)->middleware('permission:finance.manage')->name('gateway');
});

Route::livewire('/indicadores', IndicatorsPage::class)->middleware('permission:reports.view')->name('reports.indicators');
