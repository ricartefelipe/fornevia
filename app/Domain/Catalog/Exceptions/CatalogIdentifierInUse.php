<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Identificador único do catálogo (código, slug) já utilizado no tenant.
 */
final class CatalogIdentifierInUse extends ConflictingState
{
    public static function for(string $field, string $value, string $message): self
    {
        return new self($message, ['field' => $field, 'value' => $value]);
    }

    public function field(): string
    {
        $field = $this->details()['field'] ?? null;

        return is_string($field) ? $field : 'code';
    }

    public function errorCode(): string
    {
        return 'catalog_identifier_in_use';
    }
}
