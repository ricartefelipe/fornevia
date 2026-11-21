<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Shared\Quantity;

/**
 * Aloca a quantidade necessária entre depósitos, do maior para o menor
 * em prioridade (empate: id do depósito). Sem estoque suficiente:
 * - produto sem encomenda: InsufficientStock (nada é reservado);
 * - produto com encomenda explícita: reserva o disponível e registra o
 *   restante como encomendado. Nunca como efeito de falha (RN-072).
 */
final class StockAllocator
{
    /**
     * @param  list<WarehouseStock>  $stocks
     */
    public function allocate(Quantity $required, array $stocks, bool $allowBackorder, string $sku = ''): Allocation
    {
        usort($stocks, static fn (WarehouseStock $a, WarehouseStock $b): int => [-$a->priority, $a->warehouseId] <=> [-$b->priority, $b->warehouseId]);

        $remaining = $required;
        $parts = [];

        foreach ($stocks as $stock) {
            if (! $remaining->isPositive()) {
                break;
            }

            $take = Quantity::min($remaining, $stock->available());

            if ($take->isPositive()) {
                $parts[] = ['warehouseId' => $stock->warehouseId, 'quantity' => $take];
                $remaining = $remaining->minus($take);
            }
        }

        if ($remaining->isPositive() && ! $allowBackorder) {
            throw new InsufficientStock(
                'Estoque insuficiente'.($sku !== '' ? " para o SKU {$sku}" : '').'.',
                ['sku' => $sku, 'required' => $required->toDatabase(), 'missing' => $remaining->toDatabase()],
            );
        }

        return new Allocation($parts, $remaining->isPositive() ? $remaining : Quantity::zero());
    }
}
