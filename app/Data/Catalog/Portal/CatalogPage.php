<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Página da listagem do catálogo: produtos paginados, categorias do
 * filtro, filtros efetivamente aplicados e situação da empresa.
 */
final readonly class CatalogPage
{
    /**
     * @param  LengthAwarePaginator<int, CatalogProduct>  $products
     * @param  list<CategoryOption>  $categories
     */
    public function __construct(
        public LengthAwarePaginator $products,
        public array $categories,
        public CatalogFilters $filters,
        public string $companyName,
        public bool $companyCanPurchase,
        public string $companyStatusLabel,
    ) {}
}
