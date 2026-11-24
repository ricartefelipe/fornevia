<?php

declare(strict_types=1);

namespace App\Actions\Ordering;

use App\Domain\Ordering\Exceptions\OrderNotCancellable;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Approvals\BudgetLedger;
use App\Services\Audit\AuditRecorder;
use App\Services\Credit\CreditLedger;
use App\Services\Fulfillment\OrderFulfillment;
use App\Services\Fulfillment\PickingAllocations;
use App\Services\Inventory\StockLedger;
use App\Services\Ordering\OrderConfirmation;
use App\Services\Quotes\QuoteLocks;
use App\Services\Quotes\QuoteStockHolds;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento total ou parcial de itens ainda não expedidos (RF-062).
 *
 * Libera primeiro a parte encomendada (sem estoque) e depois as reservas;
 * libera o comprometimento de crédito e estorna o consumo do orçamento do
 * centro de custo proporcionais ao valor cancelado e, ao cancelar tudo, os
 * saldos restantes (sem resíduos de arredondamento). Itens expedidos exigem
 * devolução. Pedido aguardando aprovação, ou aprovado sem confirmação, é
 * cancelado por inteiro, liberando a reserva de orçamento.
 */
final readonly class CancelOrderItems
{
    public function __construct(
        private Access $access,
        private TenantDatabase $db,
        private StockLedger $stock,
        private CreditLedger $credit,
        private Outbox $outbox,
        private BudgetLedger $budgets,
        private OrderConfirmation $confirmation,
        private AuditRecorder $audit,
        private QuoteLocks $quoteLocks,
        private QuoteStockHolds $quoteHolds,
        private OrderFulfillment $fulfillment,
        private PickingAllocations $picking,
        private Clock $clock,
    ) {}

    /**
     * @param  array<string, Quantity>|null  $quantities  order_item_id => quantidade (unidade de venda); null = tudo o que for cancelável
     */
    public function handle(Order $order, ?array $quantities, string $reason): Order
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new OrderNotCancellable('Informe o motivo do cancelamento (ao menos 5 caracteres).', ['field' => 'reason']);
        }

        $this->authorize($order);

        return DB::transaction(function () use ($order, $quantities, $reason): Order {
            // Pedidos, itens, estoque, crédito e orçamento são somente leitura
            // no contexto de comprador: a gravação roda no contexto de sistema
            // do mesmo usuário (ADR-014); a auditoria, no contexto de quem
            // cancelou.
            [$locked, $audit] = $this->db->asSystem(fn (): array => $this->cancel($order, $quantities, $reason));

            $this->audit->record('order.items_cancelled', $locked, $audit, $reason);

            return $locked;
        });
    }

    /**
     * @param  array<string, Quantity>|null  $quantities
     * @return array{0: Order, 1: array<string, mixed>}
     */
    private function cancel(Order $order, ?array $quantities, string $reason): array
    {
        $locked = Order::query()
            ->where('buyer_company_id', $order->buyer_company_id)
            ->lockForUpdate()
            ->findOrFail($order->id);
        // Pedido → cotação de origem (ADR-006).
        $this->quoteLocks->lockSourceOf($locked);

        if (in_array($locked->commercial_status, [CommercialStatus::PendingApproval, CommercialStatus::Approved], true)) {
            return [$this->cancelUnconfirmed($locked, $quantities, $reason), ['items' => [], 'value_cents' => 0, 'confirmed' => false]];
        }

        if (! in_array($locked->commercial_status, [CommercialStatus::Confirmed, CommercialStatus::PartiallyCancelled], true)) {
            throw new OrderNotCancellable('O pedido não está em situação que permita cancelamento.');
        }

        $items = OrderItem::query()->where('order_id', $locked->id)->orderBy('line_number')->lockForUpdate()->get()->keyBy('id');
        $requests = $this->requests($items, $quantities);
        $cancelledValue = Money::zero();
        $changes = [];

        // Saldos na ordem (depósito, SKU), como na reserva do checkout (ADR-006).
        $this->stock->lockLevelsForOrderItems(array_values(array_map(static fn (array $r): string => $r['item']->id, $requests)));

        foreach ($requests as ['item' => $item, 'quantity' => $quantity]) {
            $base = $quantity->convertedBy($item->conversion_factor);
            $fromBackorder = Quantity::min($base, $item->quantity_backordered);
            $fromReservation = $base->minus($fromBackorder);

            $released = $fromReservation->isPositive()
                ? $this->stock->releaseForOrderItem($item->id, $fromReservation, $reason, 'order', $locked->id, $this->picking->byWarehouse($item))
                : Quantity::zero();

            $item->forceFill([
                'quantity_cancelled' => $item->quantity_cancelled->plus($quantity),
                'quantity_backordered' => $item->quantity_backordered->minus($fromBackorder),
                'quantity_reserved' => $item->quantity_reserved->minus($released),
            ])->save();

            $cancelledValue = $cancelledValue->plus($item->unit_net_price_cents->times($quantity));
            $changes[] = ['order_item_id' => $item->id, 'quantity' => $quantity->toDatabase()];
        }

        $remaining = OrderItem::query()->where('order_id', $locked->id)->get();
        $fullyCancelled = $remaining->every(static fn (OrderItem $i): bool => $i->quantity_cancelled->equals($i->quantity));

        if ($locked->credit_committed_cents->isPositive()) {
            $release = $fullyCancelled ? $locked->credit_committed_cents : Money::min($cancelledValue, $locked->credit_committed_cents);
            $this->credit->releaseCommitment($locked->buyer_company_id, $locked->id, $release, $reason);
            $locked->forceFill(['credit_committed_cents' => $locked->credit_committed_cents->minus($release)]);
        }

        // Estorno do consumo do orçamento do centro de custo (RN-055), depois
        // do crédito (ADR-006); no cancelamento total, todo o consumo restante.
        $this->budgets->reverse($locked, $fullyCancelled ? null : $cancelledValue);

        $this->fulfillment->refresh($locked);
        $locked->forceFill([
            'commercial_status' => $fullyCancelled ? CommercialStatus::Cancelled : CommercialStatus::PartiallyCancelled,
            'cancelled_at' => $fullyCancelled ? $this->clock->now() : $locked->cancelled_at,
            'cancel_reason' => $reason,
        ])->save();

        $this->confirmation->event($locked, $fullyCancelled ? 'order.cancelled' : 'order.items_cancelled', [
            'items' => $changes, 'value_cents' => $cancelledValue->cents, 'reason' => $reason,
        ]);

        $this->outbox->record(
            $fullyCancelled ? 'order.cancelled' : 'order.items_cancelled',
            'order',
            $locked->id,
            ['order_id' => $locked->id, 'number' => $locked->number, 'items' => $changes, 'value_cents' => $cancelledValue->cents],
        );

        return [$locked, ['items' => $changes, 'value_cents' => $cancelledValue->cents]];
    }

    /**
     * Pedido aguardando aprovação ou aprovado sem confirmação: não há
     * reserva de estoque nem crédito comprometido; o cancelamento é total,
     * libera a reserva de orçamento (RN-055) e encerra a aprovação ainda
     * pendente (níveis sem decisão deixam de aguardar aprovadores).
     *
     * @param  array<string, Quantity>|null  $quantities
     */
    private function cancelUnconfirmed(Order $order, ?array $quantities, string $reason): Order
    {
        if ($quantities !== null) {
            throw new OrderNotCancellable('Pedido ainda não confirmado só pode ser cancelado por inteiro.', ['field' => 'items']);
        }

        $now = $this->clock->now();

        if ($order->approval_status === ApprovalStatus::Pending) {
            OrderApprovalStep::query()
                ->where('order_id', $order->id)
                ->where('status', ApprovalStepStatus::Pending->value)
                ->update(['status' => ApprovalStepStatus::Cancelled->value, 'decided_at' => $now, 'updated_at' => $now]);
            $order->forceFill(['approval_status' => ApprovalStatus::Cancelled]);
        }

        $this->budgets->release($order);
        $this->quoteHolds->releaseForOrder($order, StockHoldEndReason::OrderCancelled);

        $order->forceFill([
            'commercial_status' => CommercialStatus::Cancelled,
            'cancelled_at' => $now,
            'cancel_reason' => $reason,
            'confirmation_issue' => null,
        ])->save();

        $this->confirmation->event($order, 'order.cancelled', ['reason' => $reason, 'confirmed' => false]);
        $this->outbox->record('order.cancelled', 'order', $order->id, ['order_id' => $order->id, 'number' => $order->number, 'confirmed' => false]);

        return $order;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, OrderItem>  $items
     * @param  array<string, Quantity>|null  $quantities
     * @return list<array{item: OrderItem, quantity: Quantity}>
     */
    private function requests(\Illuminate\Support\Collection $items, ?array $quantities): array
    {
        $requests = [];

        if ($quantities === null) {
            foreach ($items as $item) {
                $open = $item->unallocated();

                if ($open->isPositive()) {
                    $requests[] = ['item' => $item, 'quantity' => $open];
                }
            }
        } else {
            foreach ($quantities as $itemId => $quantity) {
                $item = $items->get(UntrustedId::uuid($itemId, OrderItem::class)) ?? throw new OrderNotCancellable('Item não pertence ao pedido.');
                $open = $item->unallocated();

                if (! $quantity->isPositive() || $quantity->greaterThan($open)) {
                    throw new OrderNotCancellable("Quantidade a cancelar do item {$item->sku_code} excede o saldo não expedido nem em separação.", [
                        'order_item_id' => $item->id, 'cancellable' => $open->toDatabase(),
                    ]);
                }

                $requests[] = ['item' => $item, 'quantity' => $quantity];
            }
        }

        if ($requests === []) {
            throw new OrderNotCancellable('Não há itens com saldo a cancelar.');
        }

        return $requests;
    }

    private function authorize(Order $order): void
    {
        // Equipe e clientes da API (ERP) com orders.manage.
        if ($this->access->isStaff() || $this->access->isIntegration()) {
            $this->access->authorize(Permission::OrdersManage);

            return;
        }

        if ($this->access->isBuyer() && $this->access->can(Permission::PortalOrdersPlace) && $order->buyer_company_id === $this->access->buyerCompanyId()
            && ($order->placed_by_membership_id === $this->access->membership()->id || $this->access->can(Permission::PortalOrdersViewAll))) {
            return;
        }

        throw new AuthorizationException('Sem permissão para cancelar este pedido.');
    }
}
