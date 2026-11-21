<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

use Illuminate\Support\Str;

/**
 * Filtros do catálogo do comprador, já normalizados.
 *
 * Os valores chegam da tela (entrada não confiável): a busca é aparada e
 * limitada; a categoria só é considerada quando tem formato de UUID (a
 * existência e a visibilidade são conferidas pelo serviço).
 */
final readonly class CatalogFilters
{
    public const int MAX_SEARCH_LENGTH = 100;

    public string $search;

    public ?string $categoryId;

    public function __construct(string $search = '', ?string $categoryId = null)
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($search));
        $this->search = mb_substr(is_string($normalized) ? $normalized : '', 0, self::MAX_SEARCH_LENGTH);
        $this->categoryId = $categoryId !== null && Str::isUuid($categoryId) ? strtolower($categoryId) : null;
    }

    public static function fromInput(mixed $search, mixed $categoryId): self
    {
        return new self(
            is_string($search) ? $search : '',
            is_string($categoryId) && $categoryId !== '' ? $categoryId : null,
        );
    }

    public function withoutCategory(): self
    {
        return new self($this->search);
    }

    public function isFiltered(): bool
    {
        return $this->search !== '' || $this->categoryId !== null;
    }
}
