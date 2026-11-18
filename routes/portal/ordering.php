<?php

declare(strict_types=1);

use App\Livewire\Portal\Ordering\CartPage;
use App\Livewire\Portal\Ordering\OrderConfirmation;
use App\Livewire\Portal\Ordering\OrderShow;
use App\Livewire\Portal\Ordering\OrdersIndex;
use App\Livewire\Portal\Ordering\QuickOrderPage;
use App\Livewire\Portal\Ordering\ShoppingListShow;
use App\Livewire\Portal\Ordering\ShoppingListsIndex;
use Illuminate\Support\Facades\Route;

// Carrinho, pedido rápido, listas de compra e pedidos do comprador. Já
// dentro de context:buyer, com prefixo /portal e nomes portal.*
// (routes/web.php). As Actions conferem as permissões de novo.
Route::livewire('/carrinho', CartPage::class)
    ->middleware('permission:portal.cart')
    ->name('cart');

Route::livewire('/pedido-rapido', QuickOrderPage::class)
    ->middleware('permission:portal.cart')
    ->name('quick-order');

Route::livewire('/listas', ShoppingListsIndex::class)
    ->middleware('permission:portal.cart')
    ->name('lists');

Route::livewire('/listas/{list}', ShoppingListShow::class)
    ->middleware('permission:portal.cart')
    ->name('lists.show');

// Pedidos: qualquer usuário da empresa vê os próprios; com
// portal.orders.view_all, os de toda a empresa (BuyerOrders).
Route::livewire('/pedidos', OrdersIndex::class)
    ->name('orders');

Route::livewire('/pedidos/{order}', OrderShow::class)
    ->name('orders.show');

Route::livewire('/pedidos/{order}/confirmado', OrderConfirmation::class)
    ->name('orders.confirmation');
