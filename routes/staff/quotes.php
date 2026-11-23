<?php

declare(strict_types=1);

use App\Livewire\Staff\Quotes\CommercialApprovalsIndex;
use App\Livewire\Staff\Quotes\QuoteShow;
use App\Livewire\Staff\Quotes\QuotesIndex;
use App\Livewire\Staff\Quotes\QuoteVersionForm;
use Illuminate\Support\Facades\Route;

/*
| Cotações (equipe do distribuidor). Já dentro de auth, verified,
| context:staff e two-factor (routes/web.php). As telas exigem quotes.view;
| a carteira do representante é aplicada pela consulta (StaffQuoteQuery);
| elaborar exige quotes.manage e a aprovação comercial pricing.approve,
| conferidos de novo pelas Actions.
*/

Route::middleware('permission:quotes.view')->prefix('cotacoes')->name('quotes.')->group(function (): void {
    Route::livewire('/', QuotesIndex::class)->name('index');
    // Declarada antes de {quote}.
    Route::livewire('/aprovacoes', CommercialApprovalsIndex::class)->middleware('permission:pricing.approve')->name('approvals');
    Route::livewire('/{quote}', QuoteShow::class)->whereUuid('quote')->name('show');
    Route::livewire('/{quote}/nova-versao', QuoteVersionForm::class)->middleware('permission:quotes.manage')->whereUuid('quote')->name('versions.create');
});
