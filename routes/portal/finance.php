<?php

declare(strict_types=1);

use App\Livewire\Portal\Finance\InvoiceShow;
use App\Livewire\Portal\Finance\InvoicesIndex;
use Illuminate\Support\Facades\Route;

/*
| Faturas do comprador. Já dentro de auth, verified e context:buyer, com
| prefixo /portal e nomes portal.* (routes/web.php). A consulta mostra as
| faturas dos pedidos que a pessoa vê; pagar exige portal.orders.place.
*/

Route::livewire('/faturas', InvoicesIndex::class)->name('invoices');
Route::livewire('/faturas/{invoice}', InvoiceShow::class)->whereUuid('invoice')->name('invoices.show');
