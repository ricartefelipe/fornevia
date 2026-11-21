<?php

declare(strict_types=1);

use App\Http\Controllers\Web\ProductMediaController;
use App\Livewire\Portal\Catalog\CatalogIndex;
use App\Livewire\Portal\Catalog\ProductDetail;
use Illuminate\Support\Facades\Route;

// Catálogo do comprador. Já dentro de context:buyer, com prefixo /portal e
// nomes portal.* (routes/web.php).
Route::livewire('/catalogo', CatalogIndex::class)
    ->middleware('permission:portal.catalog')
    ->name('catalog');

Route::livewire('/catalogo/{product}', ProductDetail::class)
    ->middleware('permission:portal.catalog')
    ->name('catalog.product');

// Imagens e documentos de produtos visíveis ao comprador (disco privado).
Route::get('/midias/{media}', [ProductMediaController::class, 'download'])
    ->middleware('permission:portal.catalog')
    ->name('media.download');
