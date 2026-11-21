<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Inventory;

use App\Livewire\Staff\Inventory\Concerns\InteractsWithInventory;
use App\Models\Inventory\StockLevel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Saldos por SKU e depósito (físico, reservado e disponível), na unidade
 * base do produto, com busca por código do SKU ou nome do produto e filtro
 * por depósito. Filtros são entrada não confiável: valores fora do formato
 * esperado são ignorados.
 */
#[Layout('layouts.app')]
#[Title('Estoque')]
final class StockLevelsIndex extends Component
{
    use InteractsWithInventory;
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'deposito', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'com-saldo', except: false)]
    public bool $onlyWithStock = false;

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'warehouseId', 'onlyWithStock'], true)) {
            $this->resetPage();
        }
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'warehouseId', 'onlyWithStock');
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim(mb_substr($this->search, 0, 100));
        $warehouseId = Str::isUuid($this->warehouseId) ? strtolower($this->warehouseId) : null;

        $levels = StockLevel::query()
            ->select('stock_levels.*')
            ->join('skus', static fn (JoinClause $join) => $join->on('skus.tenant_id', '=', 'stock_levels.tenant_id')->on('skus.id', '=', 'stock_levels.sku_id'))
            ->join('products', static fn (JoinClause $join) => $join->on('products.tenant_id', '=', 'skus.tenant_id')->on('products.id', '=', 'skus.product_id'))
            ->join('warehouses', static fn (JoinClause $join) => $join->on('warehouses.tenant_id', '=', 'stock_levels.tenant_id')->on('warehouses.id', '=', 'stock_levels.warehouse_id'))
            ->with(['sku:id,product_id,code,variant_label', 'sku.product:id,name,base_unit_id', 'sku.product.baseUnit:id,code', 'warehouse:id,code,name,is_active'])
            ->when($term !== '', static function (Builder $query) use ($term): void {
                $pattern = self::likePattern($term);
                $query->where(static fn (Builder $q) => $q->where('skus.code', 'ilike', $pattern)->orWhere('products.name', 'ilike', $pattern));
            })
            ->when($warehouseId !== null, static fn (Builder $query) => $query->where('stock_levels.warehouse_id', $warehouseId))
            ->when($this->onlyWithStock, static fn (Builder $query) => $query->where(static fn (Builder $q) => $q->where('stock_levels.on_hand', '>', 0)->orWhere('stock_levels.reserved', '>', 0)))
            ->orderBy('skus.code')
            ->orderByDesc('warehouses.priority')
            ->orderBy('warehouses.code')
            ->orderBy('stock_levels.id')
            ->paginate(self::PER_PAGE);

        $canManage = $this->canManageInventory();

        return view('livewire.staff.inventory.stock-levels-index', [
            'levels' => $levels,
            'warehouseOptions' => self::warehouseOptions($this->warehouses()),
            'filtered' => $term !== '' || $warehouseId !== null || $this->onlyWithStock,
            'canManage' => $canManage,
        ]);
    }
}
