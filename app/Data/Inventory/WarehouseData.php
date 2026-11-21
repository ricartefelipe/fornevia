<?php

declare(strict_types=1);

namespace App\Data\Inventory;

/**
 * Dados digitados no cadastro de depósito. Prioridade maior atende os
 * pedidos primeiro; allowsSales define se o saldo pode ser reservado.
 */
final readonly class WarehouseData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $branchId,
        public int $priority,
        public bool $allowsSales,
        public bool $isActive,
    ) {}
}
