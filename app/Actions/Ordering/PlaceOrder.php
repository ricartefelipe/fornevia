<?php

declare(strict_types=1);

namespace App\Actions\Ordering;

use App\Data\Ordering\CartLineQuote;
use App\Data\Ordering\PlaceOrderData;
use App\Domain\Approvals\ApprovalContext;
use App\Domain\Ordering\Exceptions\CartAlreadyConverted;
use App\Domain\Ordering\Exceptions\CartNotReady;
use App\Domain\Ordering\Exceptions\InvalidCheckout;
use App\Domain\Ordering\Exceptions\PricesChanged;
use App\Domain\Shared\Quantity;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Buyers\AddressKind;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CartStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Enums\Ordering\OrderSource;
use App\Models\Approvals\ApprovalRule;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Ordering\Cart;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Services\Approvals\ApprovalFingerprint;
use App\Services\Approvals\ApprovalRuleResolver;
use App\Services\Approvals\BudgetLedger;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\CartPricing;
use App\Services\Ordering\OrderConfirmation;
use App\Services\Ordering\OrderNumbering;
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
 * Ordem de locks (ADR-006): carrinho → chave de idempotência → saldos de
 * estoque (depósito, SKU) → conta de crédito → orçamento → contador do
 * plano → contador de numeração do tenant.
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
        private ApprovalRuleResolver $rules,
        private BudgetLedger $budgets,
        private OrderConfirmation $confirmation,
        private OrderNumbering $numbering,
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

        $rule = $this->rules->resolve($buyer->id, new ApprovalContext(
            total: $order->total_cents,
            costCenterId: $costCenterId,
            buyerUnitId: $unitId,
            categoryIds: $this->categoryIds($items),
            requesterRoleKey: $membership->role->key,
        ));

        if ($rule !== null) {
            $this->submitForApproval($order, $rule);
            $this->audit->record('order.submitted_for_approval', $order, ['number' => $order->number, 'total_cents' => $order->total_cents->cents, 'rule' => $rule->name]);
        } else {
            $this->confirmation->confirm($order);
            $this->audit->record('order.placed', $order, ['number' => $order->number, 'total_cents' => $order->total_cents->cents]);
        }

        $cart->forceFill(['status' => CartStatus::Converted, 'converted_order_id' => $order->id])->save();

        return [
            'resource_type' => 'order',
            'resource_id' => $order->id,
            'response' => ['number' => $order->number, 'total_cents' => $order->total_cents->cents],
        ];
    }

    /**
     * Pedido aguardando aprovação interna (RF-050): retrato dos níveis e
     * aprovadores da regra, reserva do orçamento do centro de custo (RN-055)
     * e número definitivo. Estoque e crédito só são reservados na
     * confirmação, após a aprovação (RN-056).
     */
    private function submitForApproval(Order $order, ApprovalRule $rule): void
    {
        $order->forceFill([
            'approval_status' => ApprovalStatus::Pending,
            'approval_rule_id' => $rule->id,
            'approval_rule_name' => $rule->name,
            'approval_fingerprint' => ApprovalFingerprint::of($order),
        ])->save();

        foreach ($rule->levels as $level) {
            (new OrderApprovalStep)->forceFill([
                'buyer_company_id' => $order->buyer_company_id,
                'order_id' => $order->id,
                'level_number' => $level->level_number,
                'required_approvals' => $level->required_approvals,
                // Ordem determinística: o retrato não depende da ordem de leitura.
                'approver_membership_ids' => $level->approvers->pluck('membership_id')->map(static fn (mixed $id): string => (string) $id)->sort()->values()->all(),
                'status' => ApprovalStepStatus::Pending,
            ])->save();
        }

        $this->budgets->reserve($order);

        $execution = $this->context->require();
        $this->db->run(ExecutionContext::system($execution->requireTenantId(), $execution->userId), fn () => $this->numbering->assign($order));

        $this->confirmation->event($order, 'order.submitted_for_approval', [
            'total_cents' => $order->total_cents->cents,
            'rule' => $rule->name,
            'levels' => $rule->levels->count(),
        ]);
    }

    /**
     * @param  list<array{item: OrderItem, line: CartLineQuote}>  $items
     * @return list<string>
     */
    private function categoryIds(array $items): array
    {
        return array_values(array_unique(DB::table('skus')
            ->join('products', 'products.id', '=', 'skus.product_id')
            ->whereIn('skus.id', array_map(static fn (array $pair): string => $pair['item']->sku_id, $items))
            ->whereNotNull('products.category_id')
            ->pluck('products.category_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all()));
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
            // Provisório até o fim da transação (OrderNumbering, ADR-022).
            'number' => OrderNumbering::provisional(),
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
            // Confirmado por OrderConfirmation, ou aguardando aprovação.
            'commercial_status' => CommercialStatus::PendingApproval,
            'approval_status' => ApprovalStatus::NotRequired,
            'submitted_at' => $now,
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
