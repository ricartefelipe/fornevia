<?php

declare(strict_types=1);

use App\Livewire\Staff\Catalog\CategoriesIndex;
use App\Livewire\Staff\Catalog\PrivateCatalogsIndex;
use App\Livewire\Staff\Catalog\ProductEditor;
use App\Livewire\Staff\Catalog\ProductsIndex;
use App\Livewire\Staff\Catalog\UnitsIndex;
use Illuminate\Support\Facades\Route;

/*
| Catálogo mantido pela equipe (já dentro de auth, verified, context:staff e
| two-factor). As telas exigem catalog.view; as alterações são autorizadas
| pelas Actions (catalog.manage e, para custos, catalog.view_costs).
*/

Route::middleware('permission:catalog.view')->group(function (): void {
    Route::livewire('/catalogo', ProductsIndex::class)->name('catalog.products');
    Route::livewire('/catalogo/produtos/novo', ProductEditor::class)->name('catalog.products.create');
    Route::livewire('/catalogo/produtos/{product}', ProductEditor::class)->name('catalog.products.edit');
    Route::livewire('/catalogo/categorias', CategoriesIndex::class)->name('catalog.categories');
    Route::livewire('/catalogo/unidades', UnitsIndex::class)->name('catalog.units');
    Route::livewire('/catalogo/catalogos-privados', PrivateCatalogsIndex::class)->name('catalog.private');
});
