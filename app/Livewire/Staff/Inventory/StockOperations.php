<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Inventory;

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\ReceiveStock;
use App\Actions\Inventory\TransferStock;
use App\Data\Inventory\StockBalance;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;
use App\Livewire\Staff\Inventory\Concerns\InteractsWithInventory;
use App\Models\Catalog\Sku;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\Warehouse;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Entradas de mercadoria, ajustes de inventário e transferências entre
 * depósitos, sempre na unidade base do produto e pelo ledger de estoque.
 * A tela mostra o saldo do SKU em cada depósito para orientar a operação.
 */
#[Layout('layouts.app')]
#[Title('Movimentar estoque')]
final class StockOperations extends Component
{
    use InteractsWithInventory;

    /** @var array<string, string> */
    public const array OPERATIONS = [
        'receipt' => 'Entrada de mercadoria',
        'adjustment' => 'Ajuste de inventário',
        'transfer' => 'Transferência entre depósitos',
    ];

    /** Campos das regras de negócio => propriedades do formulário. */
    private const array FIELDS = [
        'quantity' => 'quantity',
        'reason' => 'reason',
        'note' => 'reason',
        'warehouse' => 'warehouseId',
        'to_warehouse' => 'toWarehouseId',
        'sku' => 'skuSearch',
    ];

    #[Url(as: 'operacao', except: 'receipt')]
    public string $operation = 'receipt';

    public string $skuSearch = '';

    #[Locked]
    public ?string $skuId = null;

    public string $warehouseId = '';

    public string $toWarehouseId = '';

    /** Sentido do ajuste: "in" (acréscimo) ou "out" (baixa). */
    public string $direction = 'out';

    public string $quantity = '';

    /** Motivo (obrigatório no ajuste) ou observação (entrada e transferência). */
    public string $reason = '';

    public ?string $message = null;

    public function mount(): void
    {
        if (! array_key_exists($this->operation, self::OPERATIONS)) {
            $this->operation = 'receipt';
        }

        // Atalho a partir da lista de saldos: ?sku=<id>&deposito=<id>.
        $sku = request()->query('sku');
        $warehouse = request()->query('deposito');

        if (is_string($sku) && Str::isUuid($sku)) {
            $this->skuId = Sku::query()->find(strtolower($sku))?->id;
        }

        if (is_string($warehouse) && Str::isUuid($warehouse) && Warehouse::query()->whereKey(strtolower($warehouse))->exists()) {
            $this->warehouseId = strtolower($warehouse);
        }
    }

    public function updatedOperation(): void
    {
        if (! array_key_exists($this->operation, self::OPERATIONS)) {
            $this->operation = 'receipt';
        }

        $this->message = null;
        $this->resetErrorBag();
    }

    public function selectSku(string $skuId): void
    {
        $sku = Sku::query()->findOrFail(UntrustedId::uuid($skuId, Sku::class));

        $this->skuId = $sku->id;
        $this->skuSearch = '';
        $this->message = null;
        $this->resetErrorBag('skuSearch');
    }

    public function clearSku(): void
    {
        $this->reset('skuId', 'skuSearch');
    }

    public function submit(ReceiveStock $receive, AdjustStock $adjust, TransferStock $transfer): void
    {
        $this->message = null;

        $this->validate([
            'operation' => ['required', 'in:'.implode(',', array_keys(self::OPERATIONS))],
            'warehouseId' => ['required', 'uuid'],
            'toWarehouseId' => [$this->operation === 'transfer' ? 'required' : 'nullable', 'uuid'],
            'direction' => ['required', 'in:in,out'],
            'quantity' => ['required', 'string', 'max:30'],
            'reason' => [$this->operation === 'adjustment' ? 'required' : 'nullable', 'string', 'max:500'],
        ], [], [
            'operation' => 'operação',
            'warehouseId' => $this->operation === 'transfer' ? 'depósito de origem' : 'depósito',
            'toWarehouseId' => 'depósito de destino',
            'direction' => 'sentido do ajuste',
            'quantity' => 'quantidade',
            'reason' => $this->operation === 'adjustment' ? 'motivo' : 'observação',
        ]);

        if ($this->skuId === null) {
            $this->addError('skuSearch', 'Selecione um SKU pela busca.');

            return;
        }

        $quantity = $this->quantityOrError('quantity', $this->quantity);

        if ($quantity === null) {
            return;
        }

        $sku = Sku::query()->with('product.baseUnit')->findOrFail($this->skuId);
        $warehouse = Warehouse::query()->findOrFail(UntrustedId::uuid($this->warehouseId, Warehouse::class));
        $unit = $sku->product->baseUnit->code;

        try {
            $this->message = match ($this->operation) {
                'receipt' => self::receiptMessage($sku, $warehouse, $quantity, $unit, $receive->handle($warehouse, $sku, $quantity, $this->reason)),
                'adjustment' => self::adjustmentMessage($sku, $warehouse, $quantity, $unit, $this->direction === 'out', $adjust->handle(
                    $warehouse,
                    $sku,
                    $this->direction === 'out' ? Quantity::zero()->minus($quantity) : $quantity,
                    $this->reason,
                )),
                default => $this->transferMessage($sku, $warehouse, $quantity, $unit, $transfer),
            };
        } catch (BusinessRuleViolation $e) {
            $this->addViolation($e, self::FIELDS, 'quantity');

            return;
        }

        $this->reset('quantity', 'reason');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $sku = $this->skuId !== null ? Sku::query()->with('product.baseUnit')->find($this->skuId) : null;
        $warehouses = $this->warehouses();
        $active = $warehouses->where('is_active', true);

        return view('livewire.staff.inventory.stock-operations', [
            'operations' => self::OPERATIONS,
            'selectedSku' => $sku,
            'skuResults' => $sku === null ? $this->searchSkus($this->skuSearch) : new Collection,
            'balances' => $sku !== null ? self::balances($sku, $warehouses) : [],
            // Entradas e destinos de transferência só em depósitos ativos; ajustes
            // e origens também nos inativos (para corrigir ou esvaziar o saldo).
            'originOptions' => self::warehouseOptions($this->operation === 'receipt' ? $active : $warehouses),
            'destinationOptions' => self::warehouseOptions($active),
            'canManage' => $this->canManageInventory(),
        ]);
    }

    /**
     * SKUs por código ou nome do produto (no máximo 10).
     *
     * @return Collection<int, Sku>
     */
    private function searchSkus(string $term): Collection
    {
        $term = trim(mb_substr($term, 0, 100));

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $pattern = self::likePattern($term);

        return Sku::query()
            ->with('product.baseUnit')
            ->where(static fn (Builder $q) => $q->where('code', 'ilike', $pattern)
                ->orWhereHas('product', static fn (Builder $p) => $p->where('name', 'ilike', $pattern)))
            ->orderBy('code')
            ->limit(10)
            ->get();
    }

    /**
     * Saldo do SKU em cada depósito (zerado onde ainda não houve movimento).
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @return list<array{warehouse: Warehouse, balance: StockBalance}>
     */
    private static function balances(Sku $sku, Collection $warehouses): array
    {
        $levels = StockLevel::query()->where('sku_id', $sku->id)->get()->keyBy('warehouse_id');
        $rows = [];

        foreach ($warehouses as $warehouse) {
            $level = $levels->get($warehouse->id);
            $rows[] = [
                'warehouse' => $warehouse,
                'balance' => $level === null
                    ? StockBalance::empty($warehouse->id, $sku->id)
                    : new StockBalance($warehouse->id, $sku->id, $level->on_hand, $level->reserved),
            ];
        }

        return $rows;
    }

    private function transferMessage(Sku $sku, Warehouse $from, Quantity $quantity, string $unit, TransferStock $transfer): string
    {
        $to = Warehouse::query()->findOrFail(UntrustedId::uuid($this->toWarehouseId, Warehouse::class));
        $result = $transfer->handle($from, $to, $sku, $quantity, $this->reason);

        return sprintf(
            'Transferência de %s %s do SKU %s registrada: %s → %s. Disponível na origem: %s %s; no destino: %s %s.',
            $quantity->format(), $unit, $sku->code, $from->code, $to->code,
            $result->from->available()->format(), $unit, $result->to->available()->format(), $unit,
        );
    }

    private static function receiptMessage(Sku $sku, Warehouse $warehouse, Quantity $quantity, string $unit, StockBalance $balance): string
    {
        return sprintf(
            'Entrada de %s %s do SKU %s registrada no depósito %s. Físico: %s %s; disponível: %s %s.',
            $quantity->format(), $unit, $sku->code, $warehouse->code,
            $balance->onHand->format(), $unit, $balance->available()->format(), $unit,
        );
    }

    private static function adjustmentMessage(Sku $sku, Warehouse $warehouse, Quantity $quantity, string $unit, bool $outflow, StockBalance $balance): string
    {
        return sprintf(
            'Ajuste de %s de %s %s do SKU %s registrado no depósito %s. Físico: %s %s; disponível: %s %s.',
            $outflow ? 'saída' : 'entrada',
            $quantity->format(), $unit, $sku->code, $warehouse->code,
            $balance->onHand->format(), $unit, $balance->available()->format(), $unit,
        );
    }
}
