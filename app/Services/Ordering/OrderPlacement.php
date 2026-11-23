<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Data\Ordering\OrderDraft;
use App\Domain\Shared\Quantity;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\ApprovalRule;
use App\Models\Buyers\BuyerAddress;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Services\Approvals\ApprovalRuleResolver;
use App\Services\Approvals\ApprovalSnapshot;
use App\Services\Approvals\BudgetLedger;
use App\Services\Platform\PlanLimits;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\Context;
use LogicException;

/**
 * Gravação de um pedido validado pelo caminho do checkout (RF-061, RN-060):
 * assinatura ativa, pedido e itens com retratos, escolha da regra de
 * aprovação interna (RF-050) e, com regra, submissão para aprovação (níveis
 * retratados, reserva de orçamento, número); sem regra, confirmação com o
 * distribuidor (OrderConfirmation). Usada pelo carrinho e pelo aceite de
 * cotação.
 *
 * Roda no contexto de sistema do mesmo usuário (ADR-024), dentro da
 * transação de quem chama, depois de todas as validações e locks do
 * documento de origem (ADR-006).
 */
final readonly class OrderPlacement
{
    public function __construct(
        private ApprovalRuleResolver $rules,
        private BudgetLedger $budgets,
        private OrderConfirmation $confirmation,
        private OrderNumbering $numbering,
        private PlanLimits $limits,
        private Clock $clock,
    ) {}

    /**
     * @return array{0: Order, 1: ApprovalRule|null} pedido gravado e regra aplicada (null: confirmado direto)
     */
    public function place(OrderDraft $draft): array
    {
        if ($draft->lines === []) {
            throw new LogicException('Pedido sem itens.');
        }

        // Assinatura ativa antes de gravar, com ou sem aprovação (a
        // confirmação volta a verificar ao consumir a cota do plano).
        $this->limits->assertWritable();

        $order = $this->createOrder($draft);
        $this->createItems($order, $draft);
        $rule = $this->rules->forOrder($order);

        $rule !== null ? $this->submitForApproval($order, $rule) : $this->confirmation->confirm($order);

        return [$order, $rule];
    }

    /**
     * Pedido aguardando aprovação interna (RF-050): retrato dos níveis e
     * aprovadores da regra, reserva do orçamento do centro de custo (RN-055)
     * e número definitivo. Estoque e crédito só são reservados na
     * confirmação, após a aprovação (RN-056).
     */
    private function submitForApproval(Order $order, ApprovalRule $rule): void
    {
        ApprovalSnapshot::take($order, $rule);
        $this->budgets->reserve($order);
        $this->numbering->assign($order);

        $this->confirmation->event($order, 'order.submitted_for_approval', [
            'total_cents' => $order->total_cents->cents,
            'rule' => $rule->name,
            'levels' => $rule->levels->count(),
        ]);
    }

    private function createOrder(OrderDraft $draft): Order
    {
        $selection = $draft->selection;
        $order = new Order;
        $order->forceFill([
            // Provisório até o fim da transação (OrderNumbering, ADR-022).
            'number' => OrderNumbering::provisional(),
            'buyer_company_id' => $draft->buyer->id,
            'buyer_unit_id' => $selection->buyerUnitId,
            'cost_center_id' => $selection->costCenterId,
            'payment_term_id' => $selection->paymentTerm->id,
            'placed_by_user_id' => $draft->placedBy->user_id,
            'placed_by_membership_id' => $draft->placedBy->id,
            'sales_rep_membership_id' => $draft->buyer->sales_rep_membership_id,
            'source' => $draft->source,
            'source_cart_id' => $draft->sourceCartId,
            'source_quote_version_id' => $draft->sourceQuoteVersionId,
            'purchase_order_reference' => $draft->purchaseOrderReference,
            'shipping_address' => self::addressSnapshot($selection->shipping),
            'billing_address' => $selection->billing !== null ? self::addressSnapshot($selection->billing) : null,
            'payment_term' => CheckoutReferences::termSnapshot($selection->paymentTerm),
            'subtotal_cents' => $draft->totals->subtotal,
            'discount_cents' => $draft->totals->discount,
            'freight_cents' => $draft->totals->freight,
            'total_cents' => $draft->totals->total,
            'credit_committed_cents' => 0,
            // Confirmado por OrderConfirmation, ou aguardando aprovação.
            'commercial_status' => CommercialStatus::PendingApproval,
            'approval_status' => ApprovalStatus::NotRequired,
            'submitted_at' => $this->clock->now(),
            'notes' => $draft->notes,
            'correlation_id' => Context::get('correlation_id'),
        ])->save();

        return $order;
    }

    private function createItems(Order $order, OrderDraft $draft): void
    {
        foreach (array_values($draft->lines) as $index => $line) {
            (new OrderItem)->forceFill([
                'order_id' => $order->id,
                'buyer_company_id' => $order->buyer_company_id,
                'line_number' => $index + 1,
                'sku_id' => $line->skuId,
                'sku_code' => $line->skuCode,
                'product_name' => $line->productName,
                'variant_label' => $line->variantLabel,
                'unit_code' => $line->unitCode,
                'package_label' => $line->packageLabel,
                'conversion_factor' => $line->conversionFactor,
                'quantity' => $line->quantity,
                'base_quantity' => $line->baseQuantity,
                'unit_price_cents' => $line->unitPrice,
                'discount_bps' => $line->discountBps,
                'unit_net_price_cents' => $line->unitNetPrice,
                'line_gross_cents' => $line->lineGross,
                'line_total_cents' => $line->lineTotal,
                'price_source' => $line->priceSource,
                'quantity_reserved' => Quantity::zero(),
                'quantity_backordered' => Quantity::zero(),
            ])->save();
        }
    }

    /**
     * @return array<string, string|null>
     */
    public static function addressSnapshot(BuyerAddress $address): array
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
