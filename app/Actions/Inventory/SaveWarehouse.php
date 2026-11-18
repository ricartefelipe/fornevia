<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Inventory\Exceptions\InvalidInventoryOperation;
use App\Actions\Inventory\Exceptions\WarehouseCodeTaken;
use App\Data\Inventory\WarehouseData;
use App\Enums\Identity\Permission;
use App\Models\Identity\Branch;
use App\Models\Inventory\Warehouse;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cadastra ou altera um depósito: código único no tenant, nome, filial,
 * prioridade de atendimento (maior atende primeiro), situação e se o saldo
 * atende vendas. Desativar ou retirar das vendas não apaga saldos nem
 * reservas existentes: apenas impede novas reservas (e entradas, quando
 * inativo).
 */
final readonly class SaveWarehouse
{
    public const int MAX_PRIORITY = 9999;

    private const string CODE_PATTERN = '/^[A-Z0-9][A-Z0-9._-]{0,19}$/D';

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(?Warehouse $warehouse, WarehouseData $data): Warehouse
    {
        $this->access->authorize(Permission::InventoryManage);

        $code = mb_strtoupper(trim($data->code));
        $name = trim((string) preg_replace('/[\s\p{C}]+/u', ' ', $data->name));

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw InvalidInventoryOperation::field('code', 'Informe um código de até 20 caracteres: letras, números, ponto, hífen ou sublinhado, começando por letra ou número.');
        }

        if ($name === '' || mb_strlen($name) > 120) {
            throw InvalidInventoryOperation::field('name', 'Informe o nome do depósito (até 120 caracteres).');
        }

        if ($data->priority < 0 || $data->priority > self::MAX_PRIORITY) {
            throw InvalidInventoryOperation::field('priority', 'A prioridade deve estar entre 0 e '.self::MAX_PRIORITY.'.');
        }

        if (! Str::isUuid($data->branchId)) {
            throw InvalidInventoryOperation::field('branch_id', 'Selecione a filial do depósito.');
        }

        try {
            return DB::transaction(function () use ($warehouse, $data, $code, $name): Warehouse {
                $model = $warehouse === null ? new Warehouse : Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
                $branchId = strtolower($data->branchId);

                // Filial do próprio tenant (escopo e RLS); inativa só se já era a do depósito.
                $branch = Branch::query()->find($branchId);

                if ($branch === null || (! $branch->is_active && $model->getAttribute('branch_id') !== $branch->id)) {
                    throw InvalidInventoryOperation::field('branch_id', 'Selecione uma filial ativa.');
                }

                $taken = Warehouse::query()
                    ->where('code', $code)
                    ->when($model->exists, static fn ($query) => $query->whereKeyNot($model->id))
                    ->exists();

                if ($taken) {
                    throw WarehouseCodeTaken::for($code);
                }

                $creating = ! $model->exists;
                $before = $creating ? null : self::snapshot($model);

                $model->fill([
                    'code' => $code,
                    'name' => $name,
                    'priority' => $data->priority,
                    'allows_sales' => $data->allowsSales,
                    'is_active' => $data->isActive,
                ]);
                $model->forceFill(['branch_id' => $branch->id]);

                if (! $creating && ! $model->isDirty()) {
                    return $model;
                }

                $model->save();

                $this->audit->record($creating ? 'warehouse.created' : 'warehouse.updated', $model, [
                    'before' => $before,
                    'after' => self::snapshot($model),
                ]);

                return $model;
            });
        } catch (UniqueConstraintViolationException) {
            throw WarehouseCodeTaken::for($code);
        }
    }

    /**
     * @return array{code: string, name: string, branch_id: string, priority: int, allows_sales: bool, is_active: bool}
     */
    private static function snapshot(Warehouse $warehouse): array
    {
        return [
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'branch_id' => $warehouse->branch_id,
            'priority' => $warehouse->priority,
            'allows_sales' => $warehouse->allows_sales,
            'is_active' => $warehouse->is_active,
        ];
    }
}
