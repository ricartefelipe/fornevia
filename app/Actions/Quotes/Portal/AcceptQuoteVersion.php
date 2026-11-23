<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Portal;

use App\Data\Ordering\OrderDraft;
use App\Data\Ordering\OrderLineDraft;
use App\Data\Ordering\Portal\BuyerActor;
use App\Data\Quotes\AcceptQuoteData;
use App\Domain\Ordering\DocumentTotals;
use App\Domain\Ordering\LineAmounts;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteAlreadyAccepted;
use App\Domain\Quotes\Exceptions\QuoteExpired;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Domain\Shared\Percentage;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\OrderSource;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Ordering\Order;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\CheckoutReferences;
use App\Services\Ordering\OrderConfirmation;
use App\Services\Ordering\OrderPlacement;
use App\Services\Ordering\PurchaseEligibility;
use App\Services\Quotes\QuoteLocks;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantDatabase;

/**
 * Aceite da proposta, que gera o pedido (RF-040, RN-040, RN-042, ADR-025).
 *
 * Sob o lock da cotação, a versão exibida (id, impressão e situação da
 * reserva) precisa ser a vigente e estar dentro da validade; empresa,
 * itens (mesma embalagem) e condição de pagamento (mesma definição) são
 * conferidos. Na mesma transação a versão fica aceita, a cotação encerrada
 * e o pedido nasce pelo caminho do checkout (OrderPlacement) com os preços
 * da versão: aprovação interna e orçamento (ADR-023) ou confirmação, que
 * transfere a reserva da cotação ao pedido. Qualquer recusa desfaz tudo e
 * a proposta continua aceitável dentro da validade.
 *
 * Exige portal.quotes e portal.orders.place. Ordem de locks (ADR-006):
 * chave de idempotência → cotação → versões → regras → saldos → crédito →
 * orçamento → contadores. Gravação no contexto de sistema do mesmo usuário.
 */
final readonly class AcceptQuoteVersion
{
    public function __construct(
        private Access $access,
        private BuyerQuotes $quotes,
        private QuoteLocks $locks,
        private CheckoutReferences $references,
        private PurchaseEligibility $eligibility,
        private OrderPlacement $placement,
        private OrderConfirmation $confirmation,
        private IdempotencyGuard $idempotency,
        private TenantDatabase $db,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $quoteId, AcceptQuoteData $data): Order
    {
        $actor = $this->quotes->actor();
        $this->access->authorize(Permission::PortalOrdersPlace);
        $quote = $this->quotes->find($actor, $quoteId);

        $result = $this->idempotency->run('quote.accept', $data->idempotencyKey, ['quote' => $quote->id, ...$data->fingerprintPayload()], function () use ($actor, $quote, $data): array {
            [$order, $rule] = $this->db->asSystem(fn (): array => $this->accept($actor, $quote, $data));

            $this->audit->record('quote.accepted', $quote, ['version_id' => $data->reviewed->versionId, 'order_id' => $order->id, 'buyer_company_id' => $quote->buyer_company_id]);
            $rule !== null
                ? $this->audit->record('order.submitted_for_approval', $order, ['number' => $order->number, 'total_cents' => $order->total_cents->cents, 'rule' => $rule->name, 'quote_id' => $quote->id])
                : $this->audit->record('order.placed', $order, ['number' => $order->number, 'total_cents' => $order->total_cents->cents, 'quote_id' => $quote->id]);

            return ['resource_type' => 'order', 'resource_id' => $order->id, 'response' => ['number' => $order->number]];
        });

        return Order::query()->findOrFail($result->resourceId);
    }

    /**
     * @return array{0: Order, 1: \App\Models\Approvals\ApprovalRule|null}
     */
    private function accept(BuyerActor $actor, Quote $quote, AcceptQuoteData $data): array
    {
        $locked = $this->locks->lock($quote->id, $actor->company->id);
        $versions = $this->locks->versions($locked);

        if ($locked->status === QuoteStatus::Accepted) {
            $accepted = $versions->first(static fn (QuoteVersion $v): bool => $v->status === QuoteVersionStatus::Accepted);
            $number = $accepted !== null ? Order::query()->where('source_quote_version_id', $accepted->id)->value('number') : null;

            throw new QuoteAlreadyAccepted('Esta cotação já foi aceita e gerou o pedido '.($number ?? '').'.', ['order_number' => $number]);
        }

        if ($locked->status !== QuoteStatus::Proposed) {
            throw new QuoteNotOpen('A cotação não está aguardando sua resposta.');
        }

        $version = $versions->first(static fn (QuoteVersion $v): bool => $v->status === QuoteVersionStatus::Sent);

        if ($version === null || ! $data->reviewed->matches($version)) {
            throw new QuoteVersionOutdated(match (true) {
                $version !== null && $version->id === $data->reviewed->versionId => 'A reserva de estoque desta proposta terminou desde que você abriu a página. Revise antes de aceitar.',
                $version !== null => "O distribuidor enviou a versão {$version->version_number} desta proposta. Revise antes de aceitar.",
                default => 'A proposta mudou desde que você abriu esta página. Revise antes de aceitar.',
            });
        }

        $now = $this->clock->now();

        if ($version->valid_until->lessThanOrEqualTo($now)) {
            throw new QuoteExpired('A validade desta proposta terminou. Peça a revisão ao distribuidor.');
        }

        $buyer = BuyerCompany::query()->findOrFail($locked->buyer_company_id);
        $issue = $this->eligibility->companyIssue($buyer);

        if ($issue !== null) {
            throw new InvalidQuote($issue);
        }

        $items = QuoteItem::query()->where('quote_version_id', $version->id)->orderBy('line_number')->get();
        $issue = $this->eligibility->itemsIssue($buyer, $items->map(static fn (QuoteItem $item): array => [
            'sku_id' => $item->sku_id, 'sku_code' => $item->sku_code, 'quantity' => $item->quantity, 'packaging' => $item->packaging_fingerprint,
        ])->all());

        if ($issue !== null) {
            throw new InvalidQuote($issue.' Peça a revisão ao distribuidor.');
        }

        $selection = $this->references->resolve($buyer, $data->shippingAddressId, $data->billingAddressId, $version->payment_term_id, $data->buyerUnitId, $data->costCenterId);

        if (CheckoutReferences::termChanged($version->payment_term, $selection->paymentTerm)) {
            throw new InvalidQuote('A condição de pagamento desta proposta mudou. Peça a revisão ao distribuidor.', ['field' => 'payment_term']);
        }

        foreach ($versions->filter(static fn (QuoteVersion $v): bool => $v->status->isPreparing()) as $preparing) {
            $preparing->forceFill(['status' => QuoteVersionStatus::Withdrawn, 'closed_at' => $now])->save();
        }

        $version->forceFill([
            'status' => QuoteVersionStatus::Accepted,
            'accepted_at' => $now,
            'accepted_by_user_id' => $actor->membership->user_id,
            'accepted_by_membership_id' => $actor->membership->id,
            'closed_at' => $now,
        ])->save();
        $locked->forceFill(['status' => QuoteStatus::Accepted, 'closed_at' => $now, 'closed_by_user_id' => $actor->membership->user_id, 'last_activity_at' => $now])->save();

        $lines = array_values($items->map(static fn (QuoteItem $item): OrderLineDraft => new OrderLineDraft(
            skuId: $item->sku_id,
            skuCode: $item->sku_code,
            productName: $item->product_name,
            variantLabel: $item->variant_label,
            unitCode: $item->unit_code,
            packageLabel: $item->package_label,
            conversionFactor: (string) $item->conversion_factor,
            quantity: $item->quantity,
            baseQuantity: $item->base_quantity,
            unitPrice: $item->unit_price_cents,
            discountBps: $item->discount_bps,
            unitNetPrice: $item->unit_net_price_cents,
            lineGross: $item->line_gross_cents,
            lineTotal: $item->line_total_cents,
            priceSource: [...$item->price_source, 'quote' => [
                'quote_id' => $locked->id, 'number' => $locked->number, 'version_id' => $version->id,
                'version_number' => $version->version_number, 'quote_item_id' => $item->id,
            ]],
        ))->all());
        $totals = DocumentTotals::of(array_values($items->map(static fn (QuoteItem $item): LineAmounts => LineAmounts::calculate(
            $item->unit_price_cents, Percentage::ofBasisPoints($item->discount_bps), $item->quantity,
        ))->all()));

        [$order, $rule] = $this->placement->place(new OrderDraft(
            buyer: $buyer,
            placedBy: $actor->membership,
            source: OrderSource::Quote,
            sourceCartId: null,
            sourceQuoteVersionId: $version->id,
            selection: $selection,
            purchaseOrderReference: $data->purchaseOrderReference,
            notes: $data->notes,
            totals: $totals,
            lines: $lines,
        ));

        $this->confirmation->event($order, 'order.created_from_quote', [
            'quote_id' => $locked->id, 'number' => $locked->number, 'version_number' => $version->version_number,
            'stock_hold_until' => $version->stock_hold_status === StockHoldStatus::Active ? $version->stock_hold_until?->toIso8601String() : null,
        ]);
        $this->outbox->record('quote.accepted', 'quote', $locked->id, ['quote_id' => $locked->id, 'version_id' => $version->id, 'order_id' => $order->id]);

        return [$order, $rule];
    }
}
