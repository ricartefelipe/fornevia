<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Data\Inventory\ReservationOutcome;
use App\Data\Inventory\ReservationRequest;
use App\Domain\Inventory\StockAllocator;
use App\Domain\Inventory\WarehouseStock;
use App\Domain\Shared\Quantity;
use App\Enums\Inventory\ReservationHolder;
use App\Enums\Inventory\ReservationStatus;
use App\Enums\Inventory\StockMovementType;
use App\Models\Inventory\StockReservation;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Operações de estoque como ledger (ADR-005). Toda alteração de saldo é
 * um movimento; o banco aplica os deltas na projeção e garante os limites.
 *
 * Exige transação aberta em contexto de equipe ou sistema. Locks em
 * stock_levels seguem a ordem (warehouse_id, sku_id) (ADR-006).
 */
final readonly class StockLedger
{
    public function __construct(
        private StockAllocator $allocator,
        private CurrentContext $context,
    ) {}

    /**
     * Reserva os itens de um pedido ou de uma versão de cotação de forma
     * atômica: ou todos os itens são atendidos (ou explicitamente
     * encomendados), ou nada é reservado.
     *
     * @param  list<ReservationRequest>  $requests
     * @return array<string, ReservationOutcome> por titular (item de pedido ou de cotação)
     */
    public function reserve(array $requests, string $referenceType, string $referenceId): array
    {
        $this->assertInTransaction();

        if ($requests === []) {
            return [];
        }

        $skuIds = array_values(array_unique(array_map(static fn (ReservationRequest $r): string => $r->skuId, $requests)));
        $warehouses = DB::table('warehouses')
            ->where('is_active', true)
            ->where('allows_sales', true)
            ->orderBy('id')
            ->get(['id', 'priority']);

        // Bloqueia todos os saldos envolvidos de uma vez, em ordem consistente.
        $levels = DB::table('stock_levels')
            ->whereIn('warehouse_id', $warehouses->pluck('id'))
            ->whereIn('sku_id', $skuIds)
            ->orderBy('warehouse_id')
            ->orderBy('sku_id')
            ->lockForUpdate()
            ->get(['warehouse_id', 'sku_id', 'on_hand', 'reserved'])
            ->groupBy('sku_id');

        $consumed = [];
        $outcomes = [];

        foreach ($requests as $request) {
            $stocks = [];

            foreach ($warehouses as $warehouse) {
                $level = $levels->get($request->skuId)?->firstWhere('warehouse_id', $warehouse->id);
                $already = $consumed[$warehouse->id][$request->skuId] ?? Quantity::zero();
                $stocks[] = new WarehouseStock(
                    (string) $warehouse->id,
                    (int) $warehouse->priority,
                    Quantity::of($level !== null ? (string) $level->on_hand : '0'),
                    Quantity::of($level !== null ? (string) $level->reserved : '0')->plus($already),
                );
            }

            $allocation = $this->allocator->allocate($request->baseQuantity, $stocks, $request->allowBackorder, $request->skuCode);
            $reservationIds = [];

            foreach ($allocation->parts as $part) {
                $consumed[$part['warehouseId']][$request->skuId] = ($consumed[$part['warehouseId']][$request->skuId] ?? Quantity::zero())->plus($part['quantity']);
                $reservationIds[] = $this->createReservation($part['warehouseId'], $request, $part['quantity'], $referenceType, $referenceId);
            }

            $outcomes[$request->holderId] = new ReservationOutcome($request->holderId, $allocation->reserved(), $allocation->backordered, $reservationIds);
        }

        return $outcomes;
    }

    /**
     * Libera a parte ainda ativa das reservas de um item, até a quantidade
     * informada (cancelamento de itens não expedidos).
     */
    /**
     * Bloqueia, na ordem (warehouse_id, sku_id) do ADR-006, os saldos das
     * reservas ativas dos itens informados. Operações que liberam ou
     * consomem reservas de vários itens chamam este método antes de
     * percorrê-los, para não bloquear saldos na ordem das linhas do pedido.
     *
     * @param  list<string>  $orderItemIds
     */
    public function lockLevelsForOrderItems(array $orderItemIds): void
    {
        $this->lockLevels([], ReservationHolder::OrderItem, $orderItemIds);
    }

    /**
     * Bloqueia de uma vez, na ordem (warehouse_id, sku_id) do ADR-006, a
     * união dos saldos dos SKUs informados nos depósitos que atendem vendas
     * com os saldos das reservas ativas dos titulares informados. Usado ao
     * trocar ou transferir a reserva de uma cotação: o estoque liberado não
     * escapa para outra transação antes da nova reserva.
     *
     * @param  list<string>  $skuIds  SKUs que serão reservados em seguida
     * @param  list<string>  $holderIds  titulares cujas reservas ativas serão liberadas
     */
    public function lockLevels(array $skuIds, ReservationHolder $holder, array $holderIds): void
    {
        $this->assertInTransaction();

        if ($skuIds === [] && $holderIds === []) {
            return;
        }

        $query = DB::table('stock_levels')
            ->where(static function ($levels) use ($skuIds, $holder, $holderIds): void {
                if ($skuIds !== []) {
                    $levels->orWhere(static fn ($sales) => $sales
                        ->whereIn('sku_id', $skuIds)
                        ->whereIn('warehouse_id', DB::table('warehouses')->where('is_active', true)->where('allows_sales', true)->select('id')));
                }

                if ($holderIds !== []) {
                    $levels->orWhereIn(DB::raw('(warehouse_id, sku_id)'), DB::table('stock_reservations')
                        ->whereIn($holder->column(), $holderIds)
                        ->where('status', ReservationStatus::Active->value)
                        ->select(['warehouse_id', 'sku_id'])
                        ->distinct());
                }
            });

        $query->orderBy('warehouse_id')->orderBy('sku_id')->lockForUpdate()->get(['id']);
    }

    /**
     * Libera todo o saldo ativo das reservas dos itens de cotação
     * informados (fim da reserva com prazo). Exige lockLevels antes.
     *
     * @param  list<string>  $quoteItemIds
     * @return int reservas liberadas
     */
    public function releaseForQuoteItems(array $quoteItemIds, string $reason, string $referenceType, string $referenceId): int
    {
        $this->assertInTransaction();

        if ($quoteItemIds === []) {
            return 0;
        }

        $reservations = StockReservation::query()
            ->whereIn('quote_item_id', $quoteItemIds)
            ->where('status', ReservationStatus::Active->value)
            ->orderBy('warehouse_id')
            ->orderBy('sku_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            $open = $reservation->quantity->minus($reservation->quantity_consumed)->minus($reservation->quantity_released);

            $reservation->forceFill([
                'quantity_released' => $reservation->quantity_released->plus($open),
                'status' => $reservation->quantity_consumed->isPositive() ? ReservationStatus::Consumed : ReservationStatus::Released,
            ])->save();

            if ($open->isPositive()) {
                $this->movement(StockMovementType::ReservationRelease, $reservation->warehouse_id, $reservation->sku_id, $open, $reservation->id, $referenceType, $referenceId, $reason);
            }
        }

        return $reservations->count();
    }

    public function releaseForOrderItem(string $orderItemId, Quantity $quantity, string $reason, string $referenceType, string $referenceId): Quantity
    {
        $this->assertInTransaction();
        $remaining = $quantity;

        $reservations = StockReservation::query()
            ->where('order_item_id', $orderItemId)
            ->where('status', ReservationStatus::Active->value)
            ->orderBy('warehouse_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            if (! $remaining->isPositive()) {
                break;
            }

            $open = $reservation->quantity->minus($reservation->quantity_consumed)->minus($reservation->quantity_released);
            $release = Quantity::min($open, $remaining);

            if (! $release->isPositive()) {
                continue;
            }

            $released = $reservation->quantity_released->plus($release);
            $reservation->forceFill([
                'quantity_released' => $released,
                'status' => $reservation->quantity_consumed->plus($released)->equals($reservation->quantity)
                    ? ($reservation->quantity_consumed->isPositive() ? ReservationStatus::Consumed : ReservationStatus::Released)
                    : ReservationStatus::Active,
            ])->save();

            $this->movement(StockMovementType::ReservationRelease, $reservation->warehouse_id, $reservation->sku_id, $release, $reservation->id, $referenceType, $referenceId, $reason);
            $remaining = $remaining->minus($release);
        }

        return $quantity->minus($remaining);
    }

    public function receive(string $warehouseId, string $skuId, Quantity $quantity, string $reason, string $referenceType = 'manual', ?string $referenceId = null): void
    {
        $this->assertInTransaction();
        $this->movement(StockMovementType::Receipt, $warehouseId, $skuId, $quantity, null, $referenceType, $referenceId, $reason);
    }

    /**
     * Ajuste de inventário: positivo entra, negativo sai. Saída acima do
     * disponível é recusada pelo banco (físico >= reservado).
     */
    public function adjust(string $warehouseId, string $skuId, Quantity $delta, string $reason): void
    {
        $this->assertInTransaction();

        if ($delta->isZero()) {
            throw new InvalidArgumentException('Ajuste sem quantidade.');
        }

        $type = $delta->isPositive() ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut;
        $this->movement($type, $warehouseId, $skuId, $delta->isPositive() ? $delta : Quantity::zero()->minus($delta), null, 'adjustment', null, $reason);
    }

    /**
     * Transferência atômica entre depósitos: saída e entrada no mesmo
     * transfer_id e na mesma transação.
     */
    public function transfer(string $fromWarehouseId, string $toWarehouseId, string $skuId, Quantity $quantity, string $reason): string
    {
        $this->assertInTransaction();

        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Depósitos de origem e destino devem ser diferentes.');
        }

        $transferId = (string) Str::uuid7();
        $first = strcmp($fromWarehouseId, $toWarehouseId) < 0;

        // Mesma ordem de locks independentemente do sentido da transferência.
        foreach ($first ? [[StockMovementType::TransferOut, $fromWarehouseId], [StockMovementType::TransferIn, $toWarehouseId]]
            : [[StockMovementType::TransferIn, $toWarehouseId], [StockMovementType::TransferOut, $fromWarehouseId]] as [$type, $warehouseId]) {
            $this->movement($type, $warehouseId, $skuId, $quantity, null, 'transfer', $transferId, $reason, $transferId);
        }

        return $transferId;
    }

    private function createReservation(string $warehouseId, ReservationRequest $request, Quantity $quantity, string $referenceType, string $referenceId): string
    {
        $reservation = new StockReservation;
        $reservation->forceFill([
            'warehouse_id' => $warehouseId,
            'sku_id' => $request->skuId,
            $request->holder->column() => $request->holderId,
            'expires_at' => $request->expiresAt,
            'quantity' => $quantity,
            'quantity_consumed' => Quantity::zero(),
            'quantity_released' => Quantity::zero(),
            'status' => ReservationStatus::Active,
        ])->save();

        $this->movement(StockMovementType::Reservation, $warehouseId, $request->skuId, $quantity, $reservation->id, $referenceType, $referenceId, null);

        return $reservation->id;
    }

    private function movement(
        StockMovementType $type,
        string $warehouseId,
        string $skuId,
        Quantity $quantity,
        ?string $reservationId,
        ?string $referenceType,
        ?string $referenceId,
        ?string $reason,
        ?string $transferId = null,
    ): void {
        if (! $quantity->isPositive()) {
            throw new InvalidArgumentException('Movimento de estoque exige quantidade positiva.');
        }

        $zero = Quantity::zero();
        $negative = $zero->minus($quantity);
        [$onHand, $reserved] = match ($type) {
            StockMovementType::Receipt, StockMovementType::AdjustmentIn, StockMovementType::TransferIn, StockMovementType::ReturnReceipt => [$quantity, $zero],
            StockMovementType::AdjustmentOut, StockMovementType::TransferOut => [$negative, $zero],
            StockMovementType::Reservation => [$zero, $quantity],
            StockMovementType::ReservationRelease => [$zero, $negative],
            StockMovementType::Shipment => [$negative, $negative],
        };

        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->tenantId(),
            'warehouse_id' => $warehouseId,
            'sku_id' => $skuId,
            'reservation_id' => $reservationId,
            'type' => $type->value,
            'quantity' => $quantity->toDatabase(),
            'on_hand_delta' => $onHand->toDatabase(),
            'reserved_delta' => $reserved->toDatabase(),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'transfer_id' => $transferId,
            'reason' => $reason,
            'actor_user_id' => $this->context->require()->userId,
            'correlation_id' => Context::get('correlation_id'),
            'created_at' => now(),
        ]);
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Operações de estoque exigem transação aberta.');
        }
    }
}
