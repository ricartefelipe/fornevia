<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

/**
 * Página de produto do portal: SKUs ativos com a quantidade conferida e o
 * preço resolvido, galeria de imagens e documentos visíveis ao comprador.
 */
final readonly class CatalogProductDetail
{
    /**
     * @param  list<CatalogSku>  $skus
     * @param  list<CatalogMedia>  $images
     * @param  list<CatalogMedia>  $documents
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $brand,
        public ?string $description,
        public ?string $category,
        public array $skus,
        public array $images,
        public array $documents,
    ) {}
}
