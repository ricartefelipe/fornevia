<?php

declare(strict_types=1);

use App\Livewire\Staff\Orders\OrderShow;
use App\Livewire\Staff\Orders\OrdersIndex;
use Illuminate\Support\Facades\Route;

/*
| Pedidos (equipe do distribuidor). Já dentro de auth, verified,
| context:staff e two-factor (routes/web.php). As telas exigem orders.view;
| o recorte da carteira do representante é aplicado pela consulta
| (StaffOrderQuery) e o cancelamento exige orders.manage, verificado pela
| Action, inclusive nas ações Livewire, que reaplicam estes middlewares.
*/

Route::middleware('permission:orders.view')->prefix('pedidos')->name('orders.')->group(function (): void {
    Route::livewire('/', OrdersIndex::class)->name('index');
    Route::livewire('/{order}', OrderShow::class)->name('show');
});
