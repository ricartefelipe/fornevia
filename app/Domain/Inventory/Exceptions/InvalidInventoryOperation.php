<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dado de cadastro ou de movimentação de estoque fora das regras (código,
 * quantidade com casas além da unidade base, motivo ausente, depósitos
 * iguais etc.). O campo afetado vai nos detalhes.
 */
final class InvalidInventoryOperation extends BusinessRuleViolation
{
    public static function field(string $field, string $message): self
    {
        return new self($message, ['field' => $field]);
    }

    public function fieldName(): ?string
    {
        $field = $this->details()['field'] ?? null;

        return is_string($field) ? $field : null;
    }

    public function errorCode(): string
    {
        return 'invalid_inventory_operation';
    }
}
