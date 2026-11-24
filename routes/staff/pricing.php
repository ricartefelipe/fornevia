<?php

declare(strict_types=1);

use App\Livewire\Staff\Pricing\FreightIndex;
use App\Livewire\Staff\Pricing\NegotiatedPricesIndex;
use App\Livewire\Staff\Pricing\PaymentTermsIndex;
use App\Livewire\Staff\Pricing\PriceListEdit;
use App\Livewire\Staff\Pricing\PriceListsIndex;
use App\Livewire\Staff\Pricing\PriceSimulator;
use Illuminate\Support\Facades\Route;

/*
| Preços (equipe do distribuidor). Já dentro de auth, verified, context:staff
| e two-factor (routes/web.php). As telas exigem pricing.view; alterações
| são autorizadas pelas Actions (pricing.manage), inclusive nas ações
| Livewire, que também reaplicam os middlewares desta rota.
*/

Route::middleware('permission:pricing.view')->prefix('precos')->name('pricing.')->group(function (): void {
    Route::livewire('/tabelas', PriceListsIndex::class)->name('lists');
    Route::livewire('/tabelas/{priceList}', PriceListEdit::class)->name('lists.edit');
    Route::livewire('/condicoes-negociadas', NegotiatedPricesIndex::class)->name('negotiated');
    Route::livewire('/condicoes-de-pagamento', PaymentTermsIndex::class)->name('payment-terms');
    Route::livewire('/simulador', PriceSimulator::class)->name('simulator');
    Route::livewire('/frete', FreightIndex::class)->name('freight');
});
