<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Inventory;

use App\Actions\Inventory\DeleteWarehouse;
use App\Actions\Inventory\SaveWarehouse;
use App\Data\Inventory\WarehouseData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Inventory\Concerns\InteractsWithInventory;
use App\Models\Identity\Branch;
use App\Models\Inventory\Warehouse;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Depósitos do distribuidor: cadastro e edição (código, nome, filial,
 * prioridade, situação e atendimento de vendas). Depósitos com histórico
 * de estoque são desativados, nunca excluídos.
 */
#[Layout('layouts.app')]
#[Title('Depósitos')]
final class WarehousesIndex extends Component
{
    use InteractsWithInventory;
    use WithPagination;

    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $branchId = '';

    public string $priority = '0';

    public bool $allowsSales = true;

    public bool $isActive = true;

    public ?string $message = null;

    public function mount(): void
    {
        $this->branchId = self::defaultBranchId();
    }

    public function edit(string $warehouseId): void
    {
        $warehouse = Warehouse::query()->findOrFail(UntrustedId::uuid($warehouseId, Warehouse::class));

        $this->resetErrorBag();
        $this->message = null;
        $this->editingId = $warehouse->id;
        $this->code = $warehouse->code;
        $this->name = $warehouse->name;
        $this->branchId = $warehouse->branch_id;
        $this->priority = (string) $warehouse->priority;
        $this->allowsSales = $warehouse->allows_sales;
        $this->isActive = $warehouse->is_active;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'code', 'name', 'priority', 'allowsSales', 'isActive');
        $this->branchId = self::defaultBranchId();
        $this->resetErrorBag();
    }

    public function save(SaveWarehouse $save): void
    {
        $this->message = null;

        $this->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:120'],
            'branchId' => ['required', 'uuid'],
            'priority' => ['required', 'integer', 'min:0', 'max:'.SaveWarehouse::MAX_PRIORITY],
            'allowsSales' => ['boolean'],
            'isActive' => ['boolean'],
        ], [], [
            'code' => 'código',
            'name' => 'nome',
            'branchId' => 'filial',
            'priority' => 'prioridade',
        ]);

        $editing = $this->editingId !== null;

        try {
            $warehouse = $save->handle(
                $editing ? Warehouse::query()->findOrFail($this->editingId) : null,
                new WarehouseData($this->code, $this->name, $this->branchId, (int) $this->priority, $this->allowsSales, $this->isActive),
            );
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, ['code' => 'code', 'name' => 'name', 'branch_id' => 'branchId', 'priority' => 'priority'], 'code');

            return;
        }

        $this->cancel();
        $this->message = $editing ? "Depósito {$warehouse->code} atualizado." : "Depósito {$warehouse->code} cadastrado.";
    }

    public function delete(string $warehouseId, DeleteWarehouse $delete): void
    {
        $this->message = null;
        $warehouse = Warehouse::query()->findOrFail(UntrustedId::uuid($warehouseId, Warehouse::class));

        try {
            $delete->handle($warehouse);
        } catch (BusinessRuleViolation $e) {
            $this->addError('delete', $e->getMessage());

            return;
        }

        if ($this->editingId === $warehouse->id) {
            $this->cancel();
        }

        $this->resetErrorBag('delete');
        $this->message = "Depósito {$warehouse->code} excluído.";
    }

    public function render(): View
    {
        $warehouses = Warehouse::query()
            ->with('branch:id,code,name')
            ->addSelect([
                'skus_in_stock' => DB::table('stock_levels')
                    ->selectRaw('count(*)')
                    ->whereColumn('stock_levels.tenant_id', 'warehouses.tenant_id')
                    ->whereColumn('stock_levels.warehouse_id', 'warehouses.id')
                    ->where('stock_levels.on_hand', '>', 0),
                'has_history' => DB::table('stock_movements')
                    ->selectRaw('count(*) > 0')
                    ->whereColumn('stock_movements.tenant_id', 'warehouses.tenant_id')
                    ->whereColumn('stock_movements.warehouse_id', 'warehouses.id'),
            ])
            ->orderByDesc('is_active')
            ->orderByDesc('priority')
            ->orderBy('code')
            ->orderBy('id')
            ->paginate(25);

        return view('livewire.staff.inventory.warehouses-index', [
            'warehouses' => $warehouses,
            // Filiais ativas e, na edição, a filial atual do depósito (mesmo inativa).
            'branches' => Branch::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)
                    ->when(Str::isUuid($this->branchId), fn (Builder $q) => $q->orWhere('id', strtolower($this->branchId))))
                ->orderByDesc('is_headquarters')
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'is_active']),
            'canManage' => $this->canManageInventory(),
        ]);
    }

    private static function defaultBranchId(): string
    {
        $id = Branch::query()->where('is_active', true)->orderByDesc('is_headquarters')->orderBy('name')->value('id');

        return is_string($id) ? $id : '';
    }
}
