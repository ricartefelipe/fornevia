<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Inventory\Support\StockGuard;
use App\Data\Inventory\StockBalance;
use App\Domain\Inventory\Exceptions\InvalidInventoryOperation;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Sku;
use App\Models\Inventory\Warehouse;
use App\Services\Audit\AuditRecorder;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;

/**
 * Ajuste de inventário (correção de contagem, avaria, perda): positivo
 * entra, negativo sai, sempre com motivo. A decisão é tomada sob lock do
 * saldo (ADR-005): uma saída nunca deixa o físico abaixo do reservado nem
 * negativo; os CHECKs de stock_levels garantem o mesmo no banco.
 */
final readonly class AdjustStock
{
    public function __construct(
        private Access $access,
        private StockLedger $ledger,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  Quantity  $delta  variação do físico na unidade base (negativa para saída)
     */
    public function handle(Warehouse $warehouse, Sku $sku, Quantity $delta, string $reason): StockBalance
    {
        $this->access->authorize(Permission::InventoryManage);
        $sku = StockGuard::sku($sku);

        if ($delta->isZero()) {
            throw InvalidInventoryOperation::field('quantity', 'Informe uma quantidade maior que zero.');
        }

        $outflow = $delta->isNegative();
        $quantity = $outflow ? Quantity::zero()->minus($delta) : $delta;
        $unit = StockGuard::baseQuantity($sku, $quantity);
        $reason = (string) StockGuard::text($reason, 'reason', required: true);

        return StockGuard::transaction(function () use ($warehouse, $sku, $delta, $quantity, $outflow, $unit, $reason): StockBalance {
            $current = Warehouse::query()->findOrFail($warehouse->id);
            $before = StockGuard::lock($sku->id, [$current->id])[$current->id];

            if ($outflow) {
                StockGuard::assertAvailable($before, $quantity, $current, $sku, $unit, 'O ajuste de saída');
            }

            $this->ledger->adjust($current->id, $sku->id, $delta, $reason);
            $after = StockGuard::balance($current->id, $sku->id);

            $this->audit->record('stock.adjusted', $current, [
                'sku_id' => $sku->id,
                'sku_code' => $sku->code,
                'warehouse_code' => $current->code,
                'unit' => $unit->code,
                'delta' => $delta->toDatabase(),
                'on_hand_before' => $before->onHand->toDatabase(),
                'on_hand_after' => $after->onHand->toDatabase(),
                'reserved' => $after->reserved->toDatabase(),
            ], $reason);

            return $after;
        });
    }
}
