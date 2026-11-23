<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Inventory;

use App\Enums\Inventory\StockMovementType;
use App\Livewire\Staff\Inventory\Concerns\InteractsWithInventory;
use App\Models\Identity\User;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Histórico de movimentações de estoque (ledger append-only), filtrável
 * por SKU, depósito, tipo e período (datas no fuso comercial), com a
 * referência de cada movimento (pedido, ajuste, transferência) e o
 * responsável. Filtros fora do formato esperado são ignorados.
 */
#[Layout('layouts.app')]
#[Title('Movimentações de estoque')]
final class StockMovementsIndex extends Component
{
    use InteractsWithInventory;
    use WithPagination;

    public const int PER_PAGE = 50;

    /** @var array<string, string> */
    private const array REFERENCES = [
        'order' => 'Pedido',
        'quote_version' => 'Cotação',
        'adjustment' => 'Ajuste de inventário',
        'manual' => 'Entrada manual',
        'transfer' => 'Transferência',
        'shipment' => 'Expedição',
        'return' => 'Devolução',
    ];

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'deposito', except: '')]
    public string $warehouseId = '';

    #[Url(as: 'tipo', except: '')]
    public string $type = '';

    #[Url(as: 'de', except: '')]
    public string $from = '';

    #[Url(as: 'ate', except: '')]
    public string $until = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'warehouseId', 'type', 'from', 'until'], true)) {
            $this->resetPage();
        }
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'warehouseId', 'type', 'from', 'until');
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim(mb_substr($this->search, 0, 100));
        $warehouseId = Str::isUuid($this->warehouseId) ? strtolower($this->warehouseId) : null;
        $type = StockMovementType::tryFrom($this->type);
        $from = self::businessDate($this->from);
        $until = self::businessDate($this->until);
        $timezone = (string) config('app.business_timezone', 'America/Sao_Paulo');

        $movements = StockMovement::query()
            ->with(['sku:id,product_id,code', 'sku.product:id,name,base_unit_id', 'sku.product.baseUnit:id,code', 'warehouse:id,code,name'])
            ->when($term !== '', static function (Builder $query) use ($term): void {
                $pattern = self::likePattern($term);
                $query->whereHas('sku', static fn (Builder $sku) => $sku->where('code', 'ilike', $pattern)
                    ->orWhereHas('product', static fn (Builder $product) => $product->where('name', 'ilike', $pattern)));
            })
            ->when($warehouseId !== null, static fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->when($type !== null, static fn (Builder $query) => $query->where('type', $type?->value))
            // Período em datas locais: [início do dia "de", início do dia seguinte a "até"),
            // com o deslocamento explícito (independe do fuso da sessão do banco).
            ->when($from !== null, static fn (Builder $query) => $query->where('created_at', '>=', $from?->startOfDay()->format('Y-m-d H:i:sP')))
            ->when($until !== null, static fn (Builder $query) => $query->where('created_at', '<', $until?->addDay()->startOfDay()->format('Y-m-d H:i:sP')))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        $page = $movements->getCollection();

        return view('livewire.staff.inventory.stock-movements-index', [
            'movements' => $movements,
            'references' => self::references($page),
            'actors' => User::query()
                ->whereIn('id', $page->pluck('actor_user_id')->filter()->unique()->values()->all())
                ->pluck('name', 'id')
                ->all(),
            'warehouseOptions' => self::warehouseOptions($this->warehouses()),
            'types' => StockMovementType::cases(),
            'timezone' => $timezone,
            'filtered' => $term !== '' || $warehouseId !== null || $type !== null || $from !== null || $until !== null,
            'invalidPeriod' => ($this->from !== '' && $from === null) || ($this->until !== '' && $until === null),
            'canManage' => $this->canManageInventory(),
        ]);
    }

    /**
     * Data digitada (Y-m-d) no fuso comercial; nula se inválida ou fora do
     * intervalo de datas comerciais aceitas.
     */
    private static function businessDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1 || $value < BusinessDate::EARLIEST || $value > BusinessDate::LATEST) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.business_timezone', 'America/Sao_Paulo'));

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Descrição da referência de cada movimento da página: número do
     * pedido, depósito da outra ponta da transferência ou tipo de origem.
     *
     * @param  Collection<int, StockMovement>  $movements
     * @return array<string, string> por id do movimento
     */
    private static function references(Collection $movements): array
    {
        $orderIds = $movements->where('reference_type', 'order')->pluck('reference_id')->filter()->unique()->values()->all();
        $orders = $orderIds === [] ? [] : DB::table('orders')->whereIn('id', $orderIds)->pluck('number', 'id')->all();

        // Depósito da outra ponta de cada transferência, por id do movimento.
        $transferIds = $movements->pluck('transfer_id')->filter()->unique()->values()->all();
        $counterparts = [];

        if ($transferIds !== []) {
            $legs = DB::table('stock_movements')->whereIn('transfer_id', $transferIds)->get(['id', 'transfer_id', 'warehouse_id']);
            $codes = Warehouse::query()->whereIn('id', $legs->pluck('warehouse_id')->unique()->values()->all())->pluck('code', 'id')->all();

            foreach ($legs->groupBy('transfer_id') as $pair) {
                foreach ($pair as $leg) {
                    $other = $pair->firstWhere('id', '!==', $leg->id);
                    $code = $other !== null ? ($codes[$other->warehouse_id] ?? null) : null;

                    if (is_string($code)) {
                        $counterparts[(string) $leg->id] = $code;
                    }
                }
            }
        }

        $references = [];

        foreach ($movements as $movement) {
            $label = self::REFERENCES[$movement->reference_type ?? ''] ?? 'Outro documento';

            if ($movement->reference_type === 'order' && $movement->reference_id !== null) {
                $number = $orders[$movement->reference_id] ?? null;
                $label = is_string($number) ? "Pedido {$number}" : 'Pedido';
            } elseif ($movement->transfer_id !== null) {
                $code = $counterparts[$movement->id] ?? null;
                $label = $code === null ? 'Transferência' : ($movement->type === StockMovementType::TransferOut ? "Transferência para {$code}" : "Transferência de {$code}");
            }

            $references[$movement->id] = $label;
        }

        return $references;
    }
}
