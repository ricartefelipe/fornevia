<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Inventory\Exceptions\InvalidInventoryOperation;
use App\Actions\Inventory\Support\StockGuard;
use App\Data\Inventory\StockTransferResult;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Sku;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\StockLedger;
use App\Support\Auth\Access;

/**
 * Transferência entre depósitos (RN-074): saída da origem e entrada no
 * destino na mesma transação, com o mesmo transfer_id. Só o disponível da
 * origem (físico menos reservado) pode ser transferido; o destino precisa
 * estar ativo. Os saldos são bloqueados na ordem do ADR-006.
 */
final readonly class TransferStock
{
    public const string DEFAULT_NOTE = 'Transferência entre depósitos';

    public function __construct(
        private Access $access,
        private StockLedger $ledger,
    ) {}

    public function handle(Warehouse $from, Warehouse $to, Sku $sku, Quantity $quantity, ?string $note = null): StockTransferResult
    {
        $this->access->authorize(Permission::InventoryManage);
        $sku = StockGuard::sku($sku);

        if ($from->id === $to->id) {
            throw InvalidInventoryOperation::field('to_warehouse', 'Escolha um depósito de destino diferente da origem.');
        }

        $unit = StockGuard::baseQuantity($sku, $quantity);
        $note = StockGuard::text($note, 'note', required: false);

        return StockGuard::transaction(function () use ($from, $to, $sku, $quantity, $unit, $note): StockTransferResult {
            $origin = Warehouse::query()->findOrFail($from->id);
            $destination = Warehouse::query()->findOrFail($to->id);

            if (! $destination->is_active) {
                throw InvalidInventoryOperation::field('to_warehouse', "O depósito {$destination->code} está inativo e não recebe transferências.");
            }

            $balances = StockGuard::lock($sku->id, [$origin->id, $destination->id]);
            StockGuard::assertAvailable($balances[$origin->id], $quantity, $origin, $sku, $unit, 'A transferência');

            $transferId = $this->ledger->transfer($origin->id, $destination->id, $sku->id, $quantity, $note ?? self::DEFAULT_NOTE);

            return new StockTransferResult(
                $transferId,
                StockGuard::balance($origin->id, $sku->id),
                StockGuard::balance($destination->id, $sku->id),
            );
        });
    }
}
