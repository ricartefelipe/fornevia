<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Shared\Quantity;

/**
 * Resultado da alocação: partes reservadas por depósito e a quantidade
 * encomendada sem estoque (somente quando o produto admite encomenda).
 */
final readonly class Allocation
{
    /**
     * @param  list<array{warehouseId: string, quantity: Quantity}>  $parts
     */
    public function __construct(
        public array $parts,
        public Quantity $backordered,
    ) {}

    public function reserved(): Quantity
    {
        $total = Quantity::zero();

        foreach ($this->parts as $part) {
            $total = $total->plus($part['quantity']);
        }

        return $total;
    }
}
