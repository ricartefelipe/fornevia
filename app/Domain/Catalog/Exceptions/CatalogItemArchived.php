<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Produtos e SKUs arquivados são preservados para o histórico comercial e
 * não aceitam alterações.
 */
final class CatalogItemArchived extends ConflictingState
{
    public static function product(): self
    {
        return new self('O produto está arquivado e não pode ser alterado.', ['subject' => 'product']);
    }

    public static function sku(): self
    {
        return new self('O SKU está arquivado e não pode ser alterado.', ['subject' => 'sku']);
    }

    public function errorCode(): string
    {
        return 'catalog_item_archived';
    }
}
