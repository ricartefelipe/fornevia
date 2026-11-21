<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Lista de compra com dados inválidos ou alteração não permitida (apenas
 * quem criou a lista a altera; listas compartilhadas são somente leitura
 * para os demais usuários da empresa).
 */
final class InvalidShoppingList extends BusinessRuleViolation
{
    public static function field(string $field, string $message): self
    {
        return new self($message, ['field' => $field]);
    }

    public function errorCode(): string
    {
        return 'invalid_shopping_list';
    }
}
