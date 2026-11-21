<?php

declare(strict_types=1);

use App\Livewire\Staff\Inventory\StockLevelsIndex;
use App\Livewire\Staff\Inventory\StockMovementsIndex;
use App\Livewire\Staff\Inventory\StockOperations;
use App\Livewire\Staff\Inventory\WarehousesIndex;
use Illuminate\Support\Facades\Route;

/*
| Estoque (equipe do distribuidor). Já dentro de auth, verified, context:staff
| e two-factor (routes/web.php). As telas exigem inventory.view; a tela de
| movimentação também exige inventory.manage. Alterações são autorizadas
| pelas Actions (inventory.manage), inclusive nas ações Livewire, que
| reaplicam os middlewares desta rota.
*/

Route::middleware('permission:inventory.view')->prefix('estoque')->name('inventory.')->group(function (): void {
    Route::livewire('/', StockLevelsIndex::class)->name('index');
    Route::livewire('/movimentacoes', StockMovementsIndex::class)->name('movements');
    Route::livewire('/movimentar', StockOperations::class)->middleware('permission:inventory.manage')->name('operations');
    Route::livewire('/depositos', WarehousesIndex::class)->name('warehouses');
});
