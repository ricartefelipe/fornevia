<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Inventory\Exceptions\InvalidInventoryOperation;
use App\Actions\Inventory\Support\StockGuard;
use App\Data\Inventory\StockBalance;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Sku;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;

/**
 * Entrada de mercadoria (recebimento) em um depósito ativo, na unidade
 * base do produto. O movimento registra o responsável e a observação
 * (documento de origem, fornecedor), e o banco atualiza o saldo.
 */
final readonly class ReceiveStock
{
    public const string DEFAULT_NOTE = 'Entrada de mercadoria';

    public function __construct(
        private Access $access,
        private StockLedger $ledger,
    ) {}

    public function handle(Warehouse $warehouse, Sku $sku, Quantity $quantity, ?string $note = null): StockBalance
    {
        $this->access->authorize(Permission::InventoryManage);
        $sku = StockGuard::sku($sku);

        StockGuard::baseQuantity($sku, $quantity);
        $note = StockGuard::text($note, 'note', required: false);

        return StockGuard::transaction(function () use ($warehouse, $sku, $quantity, $note): StockBalance {
            $current = Warehouse::query()->findOrFail($warehouse->id);

            if (! $current->is_active) {
                throw InvalidInventoryOperation::field('warehouse', "O depósito {$current->code} está inativo e não recebe entradas.");
            }

            if ($sku->status === SkuStatus::Archived) {
                throw InvalidInventoryOperation::field('sku', "O SKU {$sku->code} está arquivado e não recebe entradas.");
            }

            $this->ledger->receive($current->id, $sku->id, $quantity, $note ?? self::DEFAULT_NOTE);

            return StockGuard::balance($current->id, $sku->id);
        });
    }
}
