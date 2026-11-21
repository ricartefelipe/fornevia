<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;

/**
 * Item que não pode entrar no carrinho ou na lista de compra: SKU
 * indisponível para a empresa, quantidade fora da regra de compra, sem
 * preço vigente ou com total acima do limite de cálculo.
 *
 * details: field (campo do formulário), sku_code e, quando houver,
 * suggestion (quantidade válida mais próxima, no formato de exibição).
 */
final class InvalidCartItem extends BusinessRuleViolation
{
    private ?Quantity $suggested = null;

    /**
     * @param  list<string>  $messages
     */
    public static function quantity(string $skuCode, array $messages, ?Quantity $suggestion, string $unitCode, string $field = 'quantity'): self
    {
        $text = implode(' ', $messages);

        if ($suggestion !== null) {
            $text .= " Quantidade válida mais próxima: {$suggestion->format()} {$unitCode}.";
        }

        $exception = new self(trim($text), [
            'field' => $field,
            'sku_code' => $skuCode,
            'suggestion' => $suggestion?->format(),
            'messages' => $messages,
        ]);
        $exception->suggested = $suggestion;

        return $exception;
    }

    public static function because(string $message, ?string $skuCode = null, string $field = 'quantity'): self
    {
        return new self($message, ['field' => $field, 'sku_code' => $skuCode]);
    }

    public function errorCode(): string
    {
        return 'invalid_cart_item';
    }

    /**
     * Quantidade válida mais próxima, quando a recusa é pela regra de compra.
     */
    public function suggestion(): ?Quantity
    {
        return $this->suggested;
    }

    public function field(): string
    {
        $field = $this->details()['field'] ?? null;

        return is_string($field) ? $field : 'quantity';
    }

    /**
     * Mensagens individuais (sem a sugestão), para relatórios por linha.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        $messages = $this->details()['messages'] ?? null;

        return is_array($messages) && $messages !== [] ? array_values(array_map('strval', $messages)) : [$this->getMessage()];
    }
}
