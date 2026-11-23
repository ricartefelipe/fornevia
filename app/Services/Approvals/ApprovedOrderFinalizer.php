<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Domain\Approvals\Exceptions\BudgetExceeded;
use App\Domain\Ordering\DocumentTotals;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Percentage;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Buyers\AddressKind;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\ApprovalRule;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Models\Pricing\PaymentTerm;
use App\Services\Ordering\CheckoutReferences;
use App\Services\Ordering\OrderConfirmation;
use App\Services\Ordering\PurchaseEligibility;
use App\Services\Pricing\PricingService;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Confirmação de pedido com aprovação interna concluída.
 *
 * Antes de confirmar (RF-061): empresa com permissão de compra, condição de
 * pagamento ainda permitida e ativa, endereço de entrega ativo, itens ainda
 * visíveis para a empresa, vendáveis e dentro das regras de compra, e preço
 * vigente para todos. Qualquer falha deixa o pedido aprovado aguardando
 * confirmação, com o motivo.
 *
 * Reavaliação (RN-054): preço vigente diferente do aprovado, definição da
 * condição de pagamento alterada (prazos ou exigência de crédito) ou pedido
 * diferente da impressão aprovada invalidam as aprovações (histórico
 * preservado). O pedido é reprecificado, a regra é escolhida de novo pelo
 * novo total e ele volta para aprovação, com a reserva de orçamento ajustada.
 * Se outra regra passar a valer, os níveis são substituídos pelos dela; se
 * nenhuma valer, os níveis retratados continuam exigidos (a reavaliação não
 * dispensa aprovação).
 *
 * A confirmação roda em savepoint: se estoque, crédito, orçamento ou cota
 * do plano recusarem (RN-056), o pedido permanece aprovado com o motivo
 * registrado e pode ser confirmado novamente depois.
 *
 * Roda no contexto de sistema do mesmo usuário, com o pedido já bloqueado
 * pelo chamador; as consultas filtram a empresa do pedido.
 */
final readonly class ApprovedOrderFinalizer
{
    private const string TERM_UNAVAILABLE = 'A condição de pagamento do pedido não está mais disponível para a empresa.';

    public function __construct(
        private PricingService $pricing,
        private BudgetLedger $budgets,
        private OrderConfirmation $confirmation,
        private ApprovalRuleResolver $rules,
        private PurchaseEligibility $eligibility,
        private CheckoutReferences $references,
        private Clock $clock,
    ) {}

    public function finalize(Order $order): ApprovalOutcome
    {
        $buyer = BuyerCompany::query()->findOrFail($order->buyer_company_id);
        $term = $this->references->allowedTerm($buyer, $order->payment_term_id);
        $issue = $this->conditionsIssue($order, $buyer, $term);

        if ($issue !== null || $term === null) {
            return $this->awaiting($order, $issue ?? self::TERM_UNAVAILABLE);
        }

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('line_number')->get();
        $issue = $this->eligibility->itemsIssue($buyer, $items->map(static fn (OrderItem $item): array => [
            'sku_id' => $item->sku_id, 'sku_code' => $item->sku_code, 'quantity' => $item->quantity,
        ])->all());

        if ($issue !== null) {
            return $this->awaiting($order, $issue);
        }

        $prices = $this->pricing->resolve($buyer, $items->mapWithKeys(static fn (OrderItem $item): array => [$item->sku_id => $item->quantity])->all());
        $changes = [];

        foreach ($items as $item) {
            $price = $prices[$item->sku_id] ?? null;

            if ($price === null) {
                return $this->awaiting($order, "O item {$item->sku_code} não tem mais preço vigente para a empresa.");
            }

            if (! $price->unitPrice->equals($item->unit_price_cents)) {
                $changes['price'] = true;
            }
        }

        if (CheckoutReferences::termChanged($order->payment_term, $term)) {
            $changes['payment_term'] = true;
        }

        if (! hash_equals((string) $order->approval_fingerprint, ApprovalFingerprint::of($order))) {
            $changes['order'] = true;
        }

        if ($changes !== []) {
            $this->invalidate($order, $items, $prices, $term, array_keys($changes));

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

    private function conditionsIssue(Order $order, BuyerCompany $buyer, ?PaymentTerm $term): ?string
    {
        $issue = $this->eligibility->companyIssue($buyer);

        if ($issue !== null) {
            return $issue;
        }

        if ($term === null) {
            return self::TERM_UNAVAILABLE;
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
     * @param  Collection<int, OrderItem>  $items
     * @param  array<string, ResolvedPrice|null>  $prices
     * @param  list<string>  $reasons  price, payment_term, order
     */
    private function invalidate(Order $order, Collection $items, array $prices, PaymentTerm $term, array $reasons): void
    {
        $previousTotal = $order->total_cents;
        $amounts = [];

        foreach ($items as $item) {
            $price = $prices[$item->sku_id] ?? null;

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

        $order->forceFill([
            'subtotal_cents' => $totals->subtotal,
            'discount_cents' => $totals->discount,
            'freight_cents' => $totals->freight,
            'total_cents' => $totals->total,
            'payment_term' => CheckoutReferences::termSnapshot($term),
            'commercial_status' => CommercialStatus::PendingApproval,
            'approval_status' => ApprovalStatus::Pending,
            'approved_at' => null,
            'confirmation_issue' => null,
        ])->save();

        // Regra pelo novo total antes do orçamento (ADR-006: regras antes do
        // orçamento, como na submissão).
        $rule = $this->rules->forOrder($order);
        $replacement = $rule !== null && $rule->id !== $order->approval_rule_id ? $rule : null;
        $this->restartSteps($order, $replacement, $now);

        $this->budgets->release($order);

        try {
            DB::transaction(fn () => $this->budgets->reserve($order));
        } catch (BudgetExceeded $e) {
            // O pedido volta para aprovação sem reserva; a confirmação exigirá
            // que o total inteiro caiba no orçamento.
            $order->forceFill(['confirmation_issue' => mb_substr($e->getMessage(), 0, 500)])->save();
        }

        $this->confirmation->event($order, 'order.approvals_invalidated', array_filter([
            'previous_total_cents' => $previousTotal->cents,
            'total_cents' => $order->total_cents->cents,
            'reasons' => $reasons,
            'rule' => $replacement?->name,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * Níveis voltam a aguardar decisão. Com outra regra aplicável ao novo
     * total, o retrato anterior é substituído (fica no histórico, com as
     * decisões invalidadas) pelos níveis da nova regra.
     */
    private function restartSteps(Order $order, ?ApprovalRule $replacement, CarbonImmutable $now): void
    {
        $open = DB::table('order_approval_steps')
            ->where('order_id', $order->id)
            ->whereIn('status', [ApprovalStepStatus::Pending->value, ApprovalStepStatus::Approved->value]);

        if ($replacement === null) {
            $open->update(['status' => ApprovalStepStatus::Pending->value, 'decided_at' => null, 'updated_at' => $now]);
            $order->forceFill(['approval_fingerprint' => ApprovalFingerprint::of($order)])->save();

            return;
        }

        $open->update(['status' => ApprovalStepStatus::Superseded->value, 'updated_at' => $now]);
        ApprovalSnapshot::take($order, $replacement);
    }
}
