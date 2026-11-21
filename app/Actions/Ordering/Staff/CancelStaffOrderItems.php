<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Staff;

use App\Actions\Ordering\CancelOrderItems;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento de itens não expedidos pela equipe (RF-062): localiza o
 * pedido pelo mesmo escopo das telas (tenant, orders.view e carteira),
 * exige orders.manage e delega a CancelOrderItems, medindo o que foi
 * liberado de reserva, de encomenda e de crédito.
 *
 * Ordem de locks (ADR-006): pedido e itens (em ordem de linha) antes da
 * Action, que os bloqueia de novo na mesma transação; o antes e o depois
 * são lidos sob o mesmo lock, sem interferência de operações concorrentes.
 */
final readonly class CancelStaffOrderItems
{
    public function __construct(
        private Access $access,
        private StaffOrderQuery $orders,
        private CancelOrderItems $cancel,
    ) {}

    /**
     * @param  array<string, Quantity>|null  $quantities  order_item_id => quantidade (unidade de venda); null = todo o saldo cancelável
     */
    public function handle(mixed $orderId, ?array $quantities, string $reason): StaffCancellationOutcome
    {
        $this->access->authorize(Permission::OrdersManage);

        return DB::transaction(function () use ($orderId, $quantities, $reason): StaffCancellationOutcome {
            $order = $this->orders->orders()->lockForUpdate()->findOrFail(UntrustedId::uuid($orderId, Order::class));
            $before = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->lockForUpdate()->get()->keyBy('id');
            $committedBefore = $order->credit_committed_cents;

            $updated = $this->cancel->handle($order, $quantities, $reason);

            $after = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get();
            $baseUnits = LoadStaffOrderDetail::baseUnits($after);
            $lines = [];

            foreach ($after as $item) {
                $previous = $before->get($item->id);

                if ($previous === null || $item->quantity_cancelled->equals($previous->quantity_cancelled)) {
                    continue;
                }

                $lines[] = [
                    'sku_code' => $item->sku_code,
                    'unit_code' => $item->unit_code,
                    'base_unit_code' => $baseUnits[$item->sku_id] ?? $item->unit_code,
                    'cancelled' => $item->quantity_cancelled->minus($previous->quantity_cancelled),
                    'reservation_released' => $previous->quantity_reserved->minus($item->quantity_reserved),
                    'backorder_released' => $previous->quantity_backordered->minus($item->quantity_backordered),
                ];
            }

            return new StaffCancellationOutcome(
                $updated,
                $lines,
                Money::max(Money::zero(), $committedBefore->minus($updated->credit_committed_cents)),
                $updated->commercial_status === CommercialStatus::Cancelled,
            );
        });
    }
}
