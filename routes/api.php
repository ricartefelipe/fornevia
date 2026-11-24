<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Middleware\AuthenticateApiClient;
use Illuminate\Support\Facades\Route;

/*
| API v1 (RF-110). Autenticação por token de cliente de API (Sanctum, com
| abilities e tenant); a requisição roda no contexto de integração do
| tenant. Contrato em docs/openapi.json.
*/

Route::prefix('v1')->name('api.v1.')->middleware(AuthenticateApiClient::class)->group(function (): void {
    Route::get('/orders', [ApiController::class, 'orders'])->name('orders.index');
    Route::get('/orders/{order}', [ApiController::class, 'order'])->name('orders.show');
    Route::get('/buyers', [ApiController::class, 'buyers'])->name('buyers.index');
    Route::get('/skus', [ApiController::class, 'skus'])->name('skus.index');
    Route::get('/stock', [ApiController::class, 'stock'])->name('stock.index');
    Route::get('/invoices', [ApiController::class, 'invoices'])->name('invoices.index');
    Route::post('/shipments/{shipment}/events', [ApiController::class, 'shipmentEvent'])->name('shipments.events.store');
    Route::post('/buyers', [ApiController::class, 'createBuyer'])->name('buyers.store');
    Route::post('/stock/receipts', [ApiController::class, 'receiveStock'])->name('stock.receipts.store');
    Route::post('/stock/adjustments', [ApiController::class, 'adjustStock'])->name('stock.adjustments.store');
    Route::post('/orders/{order}/cancellation', [ApiController::class, 'cancelOrder'])->name('orders.cancellation.store');
});
