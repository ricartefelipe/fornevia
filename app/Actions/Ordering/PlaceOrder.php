<?php

declare(strict_types=1);

namespace App\Actions\Ordering;

use App\Data\Ordering\OrderDraft;
use App\Data\Ordering\OrderLineDraft;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Ordering\Exceptions\CartAlreadyConverted;
use App\Domain\Ordering\Exceptions\CartNotReady;
use App\Domain\Ordering\Exceptions\InvalidCheckout;
use App\Domain\Ordering\Exceptions\PricesChanged;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CartStatus;
use App\Enums\Ordering\OrderSource;
use App\Models\Buyers\BuyerCompany;
use App\Models\Ordering\Cart;
use App\Models\Ordering\Order;
use App\Services\Audit\AuditRecorder;
use App\Services\Fulfillment\FreightQuoter;
use App\Services\Ordering\CartPricing;
use App\Services\Ordering\CheckoutReferences;
use App\Services\Ordering\OrderPlacement;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Tenancy\TenantDatabase;

/**
 * Confirmação transacional do pedido a partir do carrinho (RF-061, RN-060).
 *
 * Em UMA transação: valida identidade, permissões, empresa, endereço,
 * condição de pagamento, embalagens, preços vigentes (recalculados no
 * servidor), estoque e crédito; grava pedido e itens com snapshots, reservas
 * de estoque, comprometimento de crédito, consumo do plano, eventos e
 * outbox. Qualquer falha desfaz tudo: não há reserva parcial nem limite
 * consumido sem pedido.
 *
 * Quando uma regra de aprovação interna se aplica (RF-050), o pedido fica
 * aguardando aprovação: o orçamento do centro de custo é reservado e
 * estoque e crédito só são reservados na confirmação (RN-055, RN-056).
 *
 * Ordem de locks (ADR-006): chave de idempotência → carrinho → regras de
 * aprovação da empresa (compartilhado) → saldos de estoque (depósito, SKU)
 * → conta de crédito → orçamento → contador do plano → contador de
 * numeração do tenant.
 * Pedido, itens, níveis, estoque, crédito, orçamento, custos e contadores
 * são gravados no contexto de sistema do mesmo usuário (ADR-014), pois o
 * contexto de comprador só lê essas tabelas; a gravação é a mesma do aceite
 * de cotação (OrderPlacement).
 */
final readonly class PlaceOrder
{
    public function __construct(
        private Access $access,
        private TenantDatabase $db,
        private IdempotencyGuard $idempotency,
        private CartPricing $cartPricing,
        private FreightQuoter $freight,
        private CheckoutReferences $references,
        private OrderPlacement $placement,
        private AuditRecorder $audit,
    ) {}

    public function handle(PlaceOrderData $data): Order
    {
        $this->access->authorize(Permission::PortalOrdersPlace);

        $result = $this->idempotency->run(
            'order.place',
            $data->idempotencyKey,
            $data->fingerprintPayload(),
            fn (): array => $this->place($data),
        );

        return Order::query()->findOrFail($result->resourceId);
    }

    /**
     * @return array{resource_type: string, resource_id: string, response: array<string, mixed>}
     */
    private function place(PlaceOrderData $data): array
    {
        $membership = $this->access->membership();
        $cart = Cart::query()
            ->whereKey(UntrustedId::uuid($data->cartId, Cart::class))
            ->where('membership_id', $membership->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($cart->status !== CartStatus::Open) {
            $existing = Order::query()->where('source_cart_id', $cart->id)->value('number');

            throw new CartAlreadyConverted('Este carrinho já gerou o pedido '.($existing ?? '').'.', ['order_number' => $existing]);
        }

        $buyer = BuyerCompany::query()->findOrFail($cart->buyer_company_id);

        if (! $buyer->status->canPurchase()) {
            throw new InvalidCheckout('A empresa compradora está '.mb_strtolower($buyer->status->label()).' para compras.');
        }

        $selection = $this->references->resolve($buyer, $data->shippingAddressId, $data->billingAddressId, $data->paymentTermId, $data->buyerUnitId, $data->costCenterId);
        $quote = $this->cartPricing->quote($cart, $buyer);

        if (! $quote->isReady()) {
            throw new CartNotReady('Revise os itens do carrinho antes de confirmar.', ['lines' => $quote->problems()]);
        }

        if (! $quote->totals->total->isPositive()) {
            throw new CartNotReady('O total do pedido precisa ser maior que zero. Revise as quantidades.');
        }

        // Frete do distribuidor pela UF do endereço escolhido (ADR-027).
        $freight = $this->freight->quote($buyer, $selection->shipping->state, $quote->totals->itemsNet());
        $totals = $quote->totals->withFreight($freight->amount);

        if ($data->expectedTotalCents !== null && $data->expectedTotalCents !== $totals->total->cents) {
            throw new PricesChanged('Os valores mudaram desde a última exibição. Confira o novo total antes de confirmar.', [
                'expected_total_cents' => $data->expectedTotalCents,
                'current_total_cents' => $totals->total->cents,
            ]);
        }

        $draft = new OrderDraft(
            buyer: $buyer,
            placedBy: $membership,
            source: OrderSource::Cart,
            sourceCartId: $cart->id,
            sourceQuoteVersionId: null,
            selection: $selection,
            purchaseOrderReference: $data->purchaseOrderReference,
            notes: $data->notes,
            totals: $totals,
            lines: array_values(array_map(OrderLineDraft::fromCartLine(...), $quote->lines)),
        );

        // Pedidos são somente leitura no contexto de comprador: a gravação
        // roda no contexto de sistema do mesmo usuário, depois de todas as
        // validações acima (ADR-024).
        [$order, $rule] = $this->db->asSystem(fn (): array => $this->placement->place($draft));

        $rule !== null
            ? $this->audit->record('order.submitted_for_approval', $order, ['number' => $order->number, 'total_cents' => $order->total_cents->cents, 'rule' => $rule->name])
            : $this->audit->record('order.placed', $order, ['number' => $order->number, 'total_cents' => $order->total_cents->cents]);

        $cart->forceFill(['status' => CartStatus::Converted, 'converted_order_id' => $order->id])->save();

        return [
            'resource_type' => 'order',
            'resource_id' => $order->id,
            'response' => ['number' => $order->number, 'total_cents' => $order->total_cents->cents],
        ];
    }
}
