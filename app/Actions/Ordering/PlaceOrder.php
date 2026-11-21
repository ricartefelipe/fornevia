<?php

declare(strict_types=1);

namespace App\Actions\Ordering;

use App\Data\Inventory\ReservationRequest;
use App\Data\Ordering\CartLineQuote;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Ordering\Exceptions\CartAlreadyConverted;
use App\Domain\Ordering\Exceptions\CartNotReady;
use App\Domain\Ordering\Exceptions\InvalidCheckout;
use App\Domain\Ordering\Exceptions\PricesChanged;
use App\Domain\Shared\Quantity;
use App\Enums\Buyers\AddressKind;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CartStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Enums\Ordering\OrderSource;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Catalog\SkuCost;
use App\Models\Ordering\Cart;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Services\Audit\AuditRecorder;
use App\Services\Credit\CreditLedger;
use App\Services\Inventory\StockLedger;
use App\Services\Ordering\CartPricing;
use App\Services\Platform\PlanLimits;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
 * Ordem de locks (ADR-006): carrinho → chave de idempotência → saldos de
 * estoque (depósito, SKU) → conta de crédito → contador do plano →
 * contador de numeração do tenant.
 * Estoque, crédito, custos e contador são gravados no contexto de sistema
 * do mesmo usuário (ADR-014), pois o contexto de comprador só lê essas
 * tabelas.
 */
final readonly class PlaceOrder
{
    public function __construct(
        private Access $access,
        private CurrentContext $context,
        private TenantDatabase $db,
        private IdempotencyGuard $idempotency,
        private CartPricing $cartPricing,
        private StockLedger $stock,
        private CreditLedger $credit,
        private PlanLimits $limits,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
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

        [$shipping, $billing, $term, $unitId, $costCenterId] = $this->checkoutReferences($data, $buyer);

        $quote = $this->cartPricing->quote($cart, $buyer);

        if (! $quote->isReady()) {
            throw new CartNotReady('Revise os itens do carrinho antes de confirmar.', ['lines' => $quote->problems()]);
        }

        if (! $quote->totals->total->isPositive()) {
            throw new CartNotReady('O total do pedido precisa ser maior que zero. Revise as quantidades.');
        }

        if ($data->expectedTotalCents !== null && $data->expectedTotalCents !== $quote->totals->total->cents) {
            throw new PricesChanged('Os valores mudaram desde a última exibição. Confira o novo total antes de confirmar.', [
                'expected_total_cents' => $data->expectedTotalCents,
                'current_total_cents' => $quote->totals->total->cents,
            ]);
        }

        $order = $this->createOrder($cart, $buyer, $data, $shipping, $billing, $term, $unitId, $costCenterId, $quote->totals);
        $items = $this->createItems($order, $quote->lines);

        $execution = $this->context->require();

        // Estoque, crédito, custos, contador do plano e outbox: contexto de
        // sistema do mesmo usuário, na mesma transação.
        $this->db->run(ExecutionContext::system($execution->requireTenantId(), $execution->userId), function () use ($order, $items, $quote, $term, $buyer): void {
            $this->snapshotCosts($items);

            $outcomes = $this->stock->reserve(array_map(static fn (array $pair): ReservationRequest => new ReservationRequest(
                $pair['item']->id,
                $pair['line']->skuId,
                $pair['line']->skuCode,
                $pair['item']->base_quantity,
                $pair['line']->allowBackorder,
            ), $items), 'order', $order->id);

            foreach ($items as $pair) {
                $outcome = $outcomes[$pair['item']->id];
                $pair['item']->forceFill([
                    'quantity_reserved' => $outcome->reserved,
                    'quantity_backordered' => $outcome->backordered,
                ])->save();
            }

            if ((bool) $term->requires_credit) {
                $this->credit->commitForOrder($buyer->id, $order->id, $quote->totals->total);
                $order->forceFill(['credit_committed_cents' => $quote->totals->total])->save();
            }

            $this->limits->consumeOrderQuota();
            $this->assignNumber($order);

            $this->outbox->record('order.confirmed', 'order', $order->id, [
                'order_id' => $order->id,
                'number' => $order->number,
                'buyer_company_id' => $order->buyer_company_id,
                'total_cents' => $order->total_cents->cents,
                'payment_term' => $order->payment_term['code'] ?? null,
                'items' => array_map(static fn (array $pair): array => [
                    'order_item_id' => $pair['item']->id,
                    'sku_id' => $pair['item']->sku_id,
                    'quantity' => $pair['item']->quantity->toDatabase(),
                    'unit_net_price_cents' => $pair['item']->unit_net_price_cents->cents,
                ], $items),
            ]);
        });

        $this->event($order, 'order.confirmed', ['total_cents' => $order->total_cents->cents, 'items' => count($items)]);

        $cart->forceFill(['status' => CartStatus::Converted, 'converted_order_id' => $order->id])->save();
        $this->audit->record('order.placed', $order, ['number' => $order->number, 'total_cents' => $order->total_cents->cents]);

        return [
            'resource_type' => 'order',
            'resource_id' => $order->id,
            'response' => ['number' => $order->number, 'total_cents' => $order->total_cents->cents],
        ];
    }

    /**
     * Número definitivo por tenant (PED-0000001), no fim da transação: o
     * contador fica bloqueado apenas até o commit, junto do contador de uso
     * do plano, que já serializa esse trecho.
     */
    private function assignNumber(Order $order): void
    {
        $row = DB::selectOne(
            'insert into order_number_counters (tenant_id, last_number, updated_at) values (?, 1, now())
             on conflict (tenant_id) do update set last_number = order_number_counters.last_number + 1, updated_at = now()
             returning last_number',
            [$order->tenant_id],
        );

        $order->forceFill(['number' => 'PED-'.str_pad((string) $row->last_number, 7, '0', STR_PAD_LEFT)])->save();
    }

    /**
     * @return array{0: BuyerAddress, 1: BuyerAddress|null, 2: PaymentTerm, 3: string|null, 4: string|null}
     */
    private function checkoutReferences(PlaceOrderData $data, BuyerCompany $buyer): array
    {
        $shipping = BuyerAddress::query()
            ->where('buyer_company_id', $buyer->id)
            ->where('is_active', true)
            ->where('kind', AddressKind::Shipping->value)
            ->find(UntrustedId::uuid($data->shippingAddressId, BuyerAddress::class))
            ?? throw new InvalidCheckout('Endereço de entrega inválido para esta empresa.');

        $billing = $data->billingAddressId === null ? null : (BuyerAddress::query()
            ->where('buyer_company_id', $buyer->id)
            ->where('is_active', true)
            ->where('kind', AddressKind::Billing->value)
            ->find(UntrustedId::uuid($data->billingAddressId, BuyerAddress::class))
            ?? throw new InvalidCheckout('Endereço de cobrança inválido para esta empresa.'));

        $allowed = BuyerPaymentTerm::query()
            ->where('buyer_company_id', $buyer->id)
            ->where('payment_term_id', UntrustedId::uuid($data->paymentTermId, PaymentTerm::class))
            ->exists();
        $term = $allowed ? PaymentTerm::query()->where('is_active', true)->find($data->paymentTermId) : null;

        if ($term === null) {
            throw new InvalidCheckout('Condição de pagamento não disponível para esta empresa.');
        }

        $unitId = null;

        if ($data->buyerUnitId !== null) {
            $unit = BuyerUnit::query()
                ->where('buyer_company_id', $buyer->id)->where('is_active', true)
                ->whereKey(UntrustedId::uuid($data->buyerUnitId, BuyerUnit::class))
                ->first() ?? throw new InvalidCheckout('Unidade inválida para esta empresa.');
            $unitId = $unit->id;
        }

        $costCenterId = null;

        if ($data->costCenterId !== null) {
            $costCenter = CostCenter::query()
                ->where('buyer_company_id', $buyer->id)->where('is_active', true)
                ->whereKey(UntrustedId::uuid($data->costCenterId, CostCenter::class))
                ->first() ?? throw new InvalidCheckout('Centro de custo inválido para esta empresa.');
            $costCenterId = $costCenter->id;
        }

        return [$shipping, $billing, $term, $unitId, $costCenterId];
    }

    private function createOrder(Cart $cart, BuyerCompany $buyer, PlaceOrderData $data, BuyerAddress $shipping, ?BuyerAddress $billing, PaymentTerm $term, ?string $unitId, ?string $costCenterId, \App\Domain\Ordering\DocumentTotals $totals): Order
    {
        $now = $this->clock->now();
        $membership = $this->access->membership();

        $order = new Order;
        $order->forceFill([
            // Provisório até o fim da confirmação (assignNumber), para que o
            // contador por tenant fique bloqueado só no trecho final.
            'number' => 'PEND-'.Str::upper(Str::random(15)),
            'buyer_company_id' => $buyer->id,
            'buyer_unit_id' => $unitId,
            'cost_center_id' => $costCenterId,
            'payment_term_id' => $term->id,
            'placed_by_user_id' => $membership->user_id,
            'placed_by_membership_id' => $membership->id,
            'sales_rep_membership_id' => $buyer->sales_rep_membership_id,
            'source' => OrderSource::Cart,
            'source_cart_id' => $cart->id,
            'purchase_order_reference' => $data->purchaseOrderReference,
            'shipping_address' => self::addressSnapshot($shipping),
            'billing_address' => $billing !== null ? self::addressSnapshot($billing) : null,
            'payment_term' => [
                'code' => $term->code,
                'name' => $term->name,
                'installment_days' => $term->installment_days,
                'requires_credit' => $term->requires_credit,
            ],
            'subtotal_cents' => $totals->subtotal,
            'discount_cents' => $totals->discount,
            'freight_cents' => $totals->freight,
            'total_cents' => $totals->total,
            'credit_committed_cents' => 0,
            'commercial_status' => CommercialStatus::Confirmed,
            'confirmed_at' => $now,
            'notes' => $data->notes,
            'correlation_id' => Context::get('correlation_id'),
        ])->save();

        return $order;
    }

    /**
     * @param  list<CartLineQuote>  $lines
     * @return list<array{item: OrderItem, line: CartLineQuote}>
     */
    private function createItems(Order $order, array $lines): array
    {
        $items = [];

        foreach (array_values($lines) as $index => $line) {
            $amounts = $line->amounts ?? throw new CartNotReady('Item sem preço.');
            $item = new OrderItem;
            $item->forceFill([
                'order_id' => $order->id,
                'buyer_company_id' => $order->buyer_company_id,
                'line_number' => $index + 1,
                'sku_id' => $line->skuId,
                'sku_code' => $line->skuCode,
                'product_name' => $line->productName,
                'variant_label' => $line->variantLabel,
                'unit_code' => $line->unitCode,
                'package_label' => $line->packageLabel,
                'conversion_factor' => (string) ($line->conversionFactor ?? throw new CartNotReady('Conversão de unidade inválida.')),
                'quantity' => $line->quantity,
                'base_quantity' => $line->baseQuantity ?? throw new CartNotReady('Conversão de unidade inválida.'),
                'unit_price_cents' => $amounts->unitPrice,
                'discount_bps' => $amounts->discount->basisPoints,
                'unit_net_price_cents' => $amounts->unitNetPrice,
                'line_gross_cents' => $amounts->gross,
                'line_total_cents' => $amounts->total,
                'price_source' => $line->price?->snapshot() ?? [],
                'quantity_reserved' => Quantity::zero(),
                'quantity_backordered' => Quantity::zero(),
            ])->save();

            $items[] = ['item' => $item, 'line' => $line];
        }

        return $items;
    }

    /**
     * @param  list<array{item: OrderItem, line: CartLineQuote}>  $items
     */
    private function snapshotCosts(array $items): void
    {
        $costs = SkuCost::query()
            ->whereIn('sku_id', array_map(static fn (array $pair): string => $pair['item']->sku_id, $items))
            ->where('effective_from', '<=', $this->clock->now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get()
            ->unique('sku_id')
            ->keyBy('sku_id');

        foreach ($items as $pair) {
            DB::table('order_item_costs')->insert([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->context->tenantId(),
                'order_item_id' => $pair['item']->id,
                'unit_cost_cents' => $costs->get($pair['item']->sku_id)?->cost_cents->cents,
                'created_at' => $this->clock->now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function event(Order $order, string $type, array $data): void
    {
        DB::table('order_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
            'buyer_company_id' => $order->buyer_company_id,
            'type' => $type,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'actor_user_id' => $this->context->require()->userId,
            'correlation_id' => Context::get('correlation_id'),
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * @return array<string, string|null>
     */
    private static function addressSnapshot(BuyerAddress $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'recipient' => $address->recipient,
            'street' => $address->street,
            'number' => $address->number,
            'complement' => $address->complement,
            'district' => $address->district,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
        ];
    }
}
