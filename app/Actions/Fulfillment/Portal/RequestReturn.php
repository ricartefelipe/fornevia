<?php

declare(strict_types=1);

namespace App\Actions\Fulfillment\Portal;

use App\Actions\Ordering\Portal\BuyerOrders;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Domain\Fulfillment\Exceptions\InvalidReturn;
use App\Domain\Fulfillment\Exceptions\ReturnNotAllowed;
use App\Domain\Shared\Quantity;
use App\Enums\Fulfillment\ReturnReason;
use App\Enums\Fulfillment\ReturnStatus;
use App\Enums\Identity\Permission;
use App\Models\Fulfillment\ReturnItem;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\FulfillmentNumbering;
use App\Services\Ordering\OrderConfirmation;
use App\Support\Http\UntrustedId;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantDatabase;
use InvalidArgumentException;

/**
 * Pedido de devolução do comprador (RF-091) sobre itens entregues do
 * pedido que ele vê: quantidade até o entregue sem devolução ou devolução
 * aberta, motivo e descrição. Gravado no contexto de sistema do mesmo
 * usuário (ADR-024).
 *
 * Ordem de locks (ADR-006): chave de idempotência → pedido → itens →
 * contador de numeração.
 */
final readonly class RequestReturn
{
    public function __construct(
        private PortalBuyer $buyer,
        private BuyerOrders $orders,
        private IdempotencyGuard $idempotency,
        private TenantDatabase $db,
        private FulfillmentNumbering $numbering,
        private OrderConfirmation $events,
        private Outbox $outbox,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, string>  $quantities  order_item_id => quantidade (pt-BR)
     */
    public function handle(mixed $orderId, array $quantities, string $reasonCode, string $reason, string $idempotencyKey): ReturnRequest
    {
        $actor = $this->buyer->require(Permission::PortalOrdersPlace);
        $order = $this->orders->find($actor, UntrustedId::uuid($orderId, Order::class));
        $code = ReturnReason::tryFrom($reasonCode) ?? throw new InvalidReturn('Escolha o motivo da devolução.', ['field' => 'reason_code']);
        $reason = trim($reason);

        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw new InvalidReturn('Descreva o problema (de 10 a 1.000 caracteres).', ['field' => 'reason']);
        }

        $lines = [];

        foreach ($quantities as $itemId => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            try {
                $quantity = Quantity::parseLocalized($value);
            } catch (InvalidArgumentException) {
                throw new InvalidReturn('Quantidade inválida.', ['field' => "quantities.{$itemId}"]);
            }

            if ($quantity->isNegative()) {
                throw new InvalidReturn('Quantidade inválida.', ['field' => "quantities.{$itemId}"]);
            }

            if ($quantity->isPositive()) {
                $lines[UntrustedId::uuid((string) $itemId, OrderItem::class)] = $quantity;
            }
        }

        if ($lines === []) {
            throw new InvalidReturn('Informe a quantidade de ao menos um item.', ['field' => 'quantities']);
        }

        ksort($lines);
        $payload = ['order' => $order->id, 'reason' => $code->value, 'text' => $reason, 'lines' => array_map(static fn (Quantity $q): string => $q->toDatabase(), $lines)];

        $result = $this->idempotency->run('return.request', $idempotencyKey, $payload, function () use ($actor, $order, $code, $reason, $lines): array {
            $request = $this->db->asSystem(fn (): ReturnRequest => $this->create($actor->membership->id, $actor->membership->user_id, $order, $code, $reason, $lines));
            $this->audit->record('return.requested', $request, ['order_id' => $order->id, 'buyer_company_id' => $order->buyer_company_id], $reason);

            return ['resource_type' => 'return_request', 'resource_id' => $request->id, 'response' => ['number' => $request->number]];
        });

        return ReturnRequest::query()->findOrFail($result->resourceId);
    }

    /**
     * @param  array<string, Quantity>  $lines
     */
    private function create(string $membershipId, string $userId, Order $order, ReturnReason $code, string $reason, array $lines): ReturnRequest
    {
        $locked = Order::query()->where('buyer_company_id', $order->buyer_company_id)->lockForUpdate()->findOrFail($order->id);
        $items = OrderItem::query()->where('order_id', $locked->id)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($lines as $itemId => $quantity) {
            $item = $items->get($itemId) ?? throw new InvalidReturn('Item não pertence ao pedido.', ['field' => 'quantities']);

            if ($quantity->greaterThan($item->returnable())) {
                throw new ReturnNotAllowed(
                    $item->returnable()->isPositive()
                        ? "O item {$item->sku_code} admite devolver até {$item->returnable()->format()} {$item->unit_code}."
                        : "O item {$item->sku_code} não tem quantidade entregue a devolver.",
                    ['field' => "quantities.{$item->id}"],
                );
            }
        }

        $request = new ReturnRequest;
        $request->forceFill([
            'buyer_company_id' => $locked->buyer_company_id,
            'order_id' => $locked->id,
            'number' => $this->numbering->next($locked->tenant_id, 'return'),
            'status' => ReturnStatus::Requested,
            'reason_code' => $code,
            'reason' => $reason,
            'requested_by_user_id' => $userId,
            'requested_by_membership_id' => $membershipId,
        ])->save();

        foreach ($lines as $itemId => $quantity) {
            $item = $items->get($itemId);

            if ($item === null) {
                continue;
            }

            (new ReturnItem)->forceFill([
                'buyer_company_id' => $locked->buyer_company_id,
                'return_request_id' => $request->id,
                'order_item_id' => $item->id,
                'quantity' => $quantity,
            ])->save();
            $item->forceFill(['quantity_return_open' => $item->quantity_return_open->plus($quantity)])->save();
        }

        $this->events->event($locked, 'order.return_requested', ['return_id' => $request->id, 'number' => $request->number]);
        $this->outbox->record('return.requested', 'return_request', $request->id, ['return_id' => $request->id, 'order_id' => $locked->id, 'number' => $request->number]);

        return $request;
    }
}
