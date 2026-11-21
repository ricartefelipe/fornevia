<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dado do catálogo fora das regras (NCM, GTIN, casas decimais, fator de
 * conversão, prazo de encomenda etc.). O campo afetado vai nos detalhes.
 */
final class InvalidCatalogData extends BusinessRuleViolation
{
    public static function field(string $field, string $message): self
    {
        return new self($message, ['field' => $field]);
    }

    public function fieldName(): string
    {
        $field = $this->details()['field'] ?? null;

        return is_string($field) ? $field : 'name';
    }

    public function errorCode(): string
    {
        return 'invalid_catalog_data';
    }
}
