<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Inventory\Exceptions\WarehouseHasHistory;
use App\Enums\Identity\Permission;
use App\Models\Inventory\Warehouse;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Exclui um depósito cadastrado por engano, somente se ele nunca teve
 * movimentos, saldos ou reservas. Com histórico, o depósito é desativado
 * (SaveWarehouse), nunca excluído; referências de outros módulos também
 * impedem a exclusão (chaves estrangeiras com restrição).
 */
final readonly class DeleteWarehouse
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(Warehouse $warehouse): void
    {
        $this->access->authorize(Permission::InventoryManage);

        try {
            DB::transaction(function () use ($warehouse): void {
                $locked = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);

                $hasHistory = DB::table('stock_movements')->where('warehouse_id', $locked->id)->exists()
                    || DB::table('stock_levels')->where('warehouse_id', $locked->id)->exists()
                    || DB::table('stock_reservations')->where('warehouse_id', $locked->id)->exists();

                if ($hasHistory) {
                    throw WarehouseHasHistory::create($locked->code);
                }

                $snapshot = $locked->only(['code', 'name', 'branch_id', 'priority', 'allows_sales', 'is_active']);
                $locked->delete();

                $this->audit->record('warehouse.deleted', $locked, ['before' => $snapshot]);
            });
        } catch (QueryException $e) {
            // 23503: o depósito passou a ser referenciado por outro registro.
            if ($e->getCode() === '23503') {
                throw WarehouseHasHistory::create($warehouse->code);
            }

            throw $e;
        }
    }
}
