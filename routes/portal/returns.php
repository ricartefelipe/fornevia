<?php

declare(strict_types=1);

use App\Livewire\Portal\Returns\ReturnShow;
use App\Livewire\Portal\Returns\ReturnsIndex;
use Illuminate\Support\Facades\Route;

/*
| Devoluções do comprador. Já dentro de auth, verified e context:buyer, com
| prefixo /portal e nomes portal.* (routes/web.php). A consulta mostra as
| devoluções dos pedidos que a pessoa vê; pedir e desistir exigem
| portal.orders.place, verificado pelas Actions.
*/

Route::livewire('/devolucoes', ReturnsIndex::class)->name('returns');
Route::livewire('/devolucoes/{return}', ReturnShow::class)->whereUuid('return')->name('returns.show');
