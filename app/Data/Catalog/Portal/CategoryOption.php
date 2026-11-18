<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

/**
 * Categoria disponível no filtro do catálogo (apenas categorias com
 * produtos visíveis ao comprador e seus ancestrais).
 */
final readonly class CategoryOption
{
    public function __construct(
        public string $id,
        public string $name,
        public int $depth,
    ) {}

    /**
     * Rótulo com recuo pela profundidade na hierarquia.
     */
    public function label(): string
    {
        return str_repeat('— ', $this->depth).$this->name;
    }
}
