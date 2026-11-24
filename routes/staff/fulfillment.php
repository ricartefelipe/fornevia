<?php

declare(strict_types=1);

use App\Livewire\Staff\Fulfillment\FulfillmentIndex;
use App\Livewire\Staff\Fulfillment\FulfillmentOrder;
use App\Livewire\Staff\Fulfillment\ShipmentShow;
use App\Livewire\Staff\Returns\ReturnShow;
use App\Livewire\Staff\Returns\ReturnsIndex;
use Illuminate\Support\Facades\Route;

/*
| Expedição e devoluções (equipe do distribuidor). Já dentro de auth,
| verified, context:staff e two-factor (routes/web.php). As telas exigem
| fulfillment.view ou returns.view; as operações são autorizadas pelas
| Actions (fulfillment.manage e returns.manage).
*/

Route::middleware('permission:fulfillment.view')->prefix('expedicao')->name('fulfillment.')->group(function (): void {
    Route::livewire('/', FulfillmentIndex::class)->name('index');
    Route::livewire('/pedidos/{order}', FulfillmentOrder::class)->whereUuid('order')->name('orders.show');
    Route::livewire('/remessas/{shipment}', ShipmentShow::class)->whereUuid('shipment')->name('shipments.show');
});

Route::middleware('permission:returns.view')->prefix('devolucoes')->name('returns.')->group(function (): void {
    Route::livewire('/', ReturnsIndex::class)->name('index');
    Route::livewire('/{return}', ReturnShow::class)->whereUuid('return')->name('show');
});
