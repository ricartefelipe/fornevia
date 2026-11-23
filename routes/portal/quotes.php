<?php

declare(strict_types=1);

use App\Livewire\Portal\Quotes\QuoteShow;
use App\Livewire\Portal\Quotes\QuotesIndex;
use Illuminate\Support\Facades\Route;

// Cotações do comprador (RF-040). Já dentro de context:buyer, com prefixo
// /portal e nomes portal.* (routes/web.php). As consultas e as Actions
// conferem as permissões de novo; aceitar exige também portal.orders.place.
Route::livewire('/cotacoes', QuotesIndex::class)
    ->middleware('permission:portal.quotes')
    ->name('quotes');

Route::livewire('/cotacoes/{quote}', QuoteShow::class)
    ->middleware('permission:portal.quotes')
    ->whereUuid('quote')
    ->name('quotes.show');
