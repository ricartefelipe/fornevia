<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

/**
 * Produto na listagem do catálogo do comprador, com os SKUs ativos e o
 * preço de cada um na quantidade mínima de compra.
 */
final readonly class CatalogProduct
{
    /**
     * @param  list<CatalogSku>  $skus
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $brand,
        public ?string $category,
        public ?CatalogMedia $thumbnail,
        public array $skus,
    ) {}
}
