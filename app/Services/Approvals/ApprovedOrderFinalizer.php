<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Domain\Ordering\DocumentTotals;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Percentage;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Buyers\AddressKind;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Models\Pricing\BuyerPaymentTerm;
use App\Models\Pricing\PaymentTerm;
use App\Services\Ordering\OrderConfirmation;
use App\Services\Pricing\PricingService;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Confirmação de pedido com aprovação interna concluída.
 *
 * Reavaliação (RN-054): se o preço vigente de algum item mudou desde a
 * submissão, o pedido é reprecificado, as aprovações são invalidadas
 * (histórico preservado) e ele volta para aprovação, com a reserva de
 * orçamento ajustada ao novo total. Empresa sem permissão de compra,
 * condição de pagamento não mais permitida ou endereço inativo impedem a
 * confirmação.
 *
 * A confirmação roda em savepoint: se estoque, crédito, orçamento ou cota
 * do plano recusarem (RN-056), o pedido permanece aprovado com o motivo
 * registrado e pode ser confirmado novamente depois.
 *
 * O chamador já bloqueou o pedido.
 */
final readonly class ApprovedOrderFinalizer
{
    public function __construct(
        private PricingService $pricing,
        private BudgetLedger $budgets,
        private OrderConfirmation $confirmation,
        private Clock $clock,
    ) {}

    public function finalize(Order $order): ApprovalOutcome
    {
        $issue = $this->conditionsIssue($order);

        if ($issue !== null) {
            return $this->awaiting($order, $issue);
        }

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get();
        $buyer = BuyerCompany::query()->findOrFail($order->buyer_company_id);
        $prices = $this->pricing->resolve($buyer, $items->mapWithKeys(static fn (OrderItem $item): array => [$item->sku_id => $item->quantity])->all());

        $changed = false;

        foreach ($items as $item) {
            $price = $prices[$item->sku_id] ?? null;

            if ($price === null) {
                return $this->awaiting($order, "O item {$item->sku_code} não tem mais preço vigente para a empresa.");
            }

            $changed = $changed || ! $price->unitPrice->equals($item->unit_price_cents);
        }

        if ($changed) {
            $this->invalidate($order, $items, $prices);

            return ApprovalOutcome::Invalidated;
        }

        try {
            DB::transaction(fn () => $this->confirmation->confirm($order));
        } catch (BusinessRuleViolation $e) {
            $order->refresh();

            return $this->awaiting($order, $e->getMessage());
        }

        return ApprovalOutcome::Confirmed;
    }

    private function conditionsIssue(Order $order): ?string
    {
        $buyer = BuyerCompany::query()->findOrFail($order->buyer_company_id);

        if (! $buyer->status->canPurchase()) {
            return 'A empresa compradora está '.mb_strtolower($buyer->status->label()).' para compras.';
        }

        $termAllowed = BuyerPaymentTerm::query()
            ->where('buyer_company_id', $order->buyer_company_id)
            ->where('payment_term_id', $order->payment_term_id)
            ->exists()
            && PaymentTerm::query()->whereKey($order->payment_term_id)->where('is_active', true)->exists();

        if (! $termAllowed) {
            return 'A condição de pagamento do pedido não está mais disponível para a empresa.';
        }

        $addressId = $order->shipping_address['id'] ?? null;
        $addressActive = is_string($addressId) && BuyerAddress::query()
            ->whereKey($addressId)
            ->where('buyer_company_id', $order->buyer_company_id)
            ->where('kind', AddressKind::Shipping->value)
            ->where('is_active', true)
            ->exists();

        return $addressActive ? null : 'O endereço de entrega do pedido não está mais ativo.';
    }

    private function awaiting(Order $order, string $issue): ApprovalOutcome
    {
        $order->forceFill([
            'commercial_status' => CommercialStatus::Approved,
            'confirmation_issue' => mb_substr($issue, 0, 500),
        ])->save();

        $this->confirmation->event($order, 'order.confirmation_pending', ['reason' => $issue]);

        return ApprovalOutcome::AwaitingConfirmation;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, OrderItem>  $items
     * @param  array<string, \App\Domain\Pricing\ResolvedPrice|null>  $prices
     */
    private function invalidate(Order $order, \Illuminate\Database\Eloquent\Collection $items, array $prices): void
    {
        $previousTotal = $order->total_cents;
        $amounts = [];

        foreach ($items as $item) {
            $price = $prices[$item->sku_id];

            if ($price === null) {
                continue;
            }

            $line = LineAmounts::calculate($price->unitPrice, Percentage::zero(), $item->quantity);
            $amounts[] = $line;
            $item->forceFill([
                'unit_price_cents' => $line->unitPrice,
                'discount_bps' => $line->discount->basisPoints,
                'unit_net_price_cents' => $line->unitNetPrice,
                'line_gross_cents' => $line->gross,
                'line_total_cents' => $line->total,
                'price_source' => $price->snapshot(),
            ])->save();
        }

        $totals = DocumentTotals::of($amounts);
        $now = $this->clock->now();

        DB::table('order_approval_decisions')
            ->where('order_id', $order->id)
            ->whereNull('invalidated_at')
            ->update(['invalidated_at' => $now]);
        DB::table('order_approval_steps')
            ->where('order_id', $order->id)
            ->update(['status' => ApprovalStepStatus::Pending->value, 'decided_at' => null, 'updated_at' => $now]);

        $this->budgets->release($order);

        $order->forceFill([
            'subtotal_cents' => $totals->subtotal,
            'discount_cents' => $totals->discount,
            'freight_cents' => $totals->freight,
            'total_cents' => $totals->total,
            'commercial_status' => CommercialStatus::PendingApproval,
            'approval_status' => ApprovalStatus::Pending,
            'approved_at' => null,
            'confirmation_issue' => null,
        ])->save();
        $order->forceFill(['approval_fingerprint' => ApprovalFingerprint::of($order)])->save();

        $this->budgets->reserve($order);

        $this->confirmation->event($order, 'order.approvals_invalidated', [
            'previous_total_cents' => $previousTotal->cents,
            'total_cents' => $order->total_cents->cents,
        ]);
    }
}
