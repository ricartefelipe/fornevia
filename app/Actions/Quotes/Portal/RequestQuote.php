<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Portal;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\CartLineQuote;
use App\Data\Quotes\RequestQuoteData;
use App\Domain\Ordering\Exceptions\CartNotReady;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Enums\Identity\Permission;
use App\Enums\Ordering\CartStatus;
use App\Enums\Quotes\QuoteRequestKind;
use App\Enums\Quotes\QuoteStatus;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteRequest;
use App\Models\Quotes\QuoteRequestItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Ordering\CartPricing;
use App\Services\Ordering\PurchaseEligibility;
use App\Services\Quotes\QuoteNumbering;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Idempotency\IdempotencyGuard;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;

/**
 * Solicitação de cotação pelo portal, a partir do carrinho da própria
 * associação (RF-040). Os itens precisam estar visíveis, vendáveis e
 * dentro das regras de compra; falta de preço ou de estoque não impede (é
 * o que a negociação vai resolver). Sem preço: a proposta é da equipe.
 *
 * Ordem de locks (ADR-006): chave de idempotência → carrinho → contador de
 * cotações. A cotação é gravada no contexto de sistema do mesmo usuário
 * (ADR-024); os itens do carrinho, quando pedido, saem no contexto de
 * comprador.
 */
final readonly class RequestQuote
{
    public function __construct(
        private PortalBuyer $buyer,
        private IdempotencyGuard $idempotency,
        private CartPricing $cartPricing,
        private PurchaseEligibility $eligibility,
        private QuoteNumbering $numbering,
        private TenantDatabase $db,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(RequestQuoteData $data): Quote
    {
        $actor = $this->buyer->require(Permission::PortalQuotes);
        $this->buyer->require(Permission::PortalCart);

        $message = $data->message !== null && trim($data->message) !== '' ? trim($data->message) : null;

        if ($message !== null && mb_strlen($message) > 2000) {
            throw new InvalidQuote('A mensagem deve ter até 2.000 caracteres.', ['field' => 'message']);
        }

        $neededBy = $this->neededBy($data->neededBy);

        $result = $this->idempotency->run('quote.request', $data->idempotencyKey, $data->fingerprintPayload(), function () use ($actor, $data, $message, $neededBy): array {
            $cart = Cart::query()
                ->whereKey(UntrustedId::uuid($data->cartId, Cart::class))
                ->where('membership_id', $actor->membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($cart->status !== CartStatus::Open) {
                throw new CartNotReady('Este carrinho já foi convertido em pedido.');
            }

            $issue = $this->eligibility->companyIssue($actor->company);

            if ($issue !== null) {
                throw new InvalidQuote($issue);
            }

            $lines = $this->cartPricing->quote($cart, $actor->company)->lines;

            if ($lines === []) {
                throw new CartNotReady('Adicione itens ao carrinho antes de solicitar a cotação.');
            }

            if (count($lines) > (int) config('quotes.max_lines')) {
                throw new InvalidQuote('A cotação aceita até '.config('quotes.max_lines').' itens.', ['field' => 'items']);
            }

            $issue = $this->eligibility->itemsIssue($actor->company, array_map(static fn (CartLineQuote $line): array => [
                'sku_id' => $line->skuId, 'sku_code' => $line->skuCode, 'quantity' => $line->quantity,
            ], $lines));

            if ($issue !== null) {
                throw new CartNotReady($issue.' Revise o carrinho antes de solicitar a cotação.');
            }

            $quote = $this->db->asSystem(fn (): Quote => $this->record($actor, $lines, $message, $neededBy));

            if ($data->removeFromCart) {
                CartItem::query()->where('cart_id', $cart->id)->whereIn('id', array_map(static fn (CartLineQuote $l): string => $l->cartItemId, $lines))->delete();
            }

            $this->audit->record('quote.requested', $quote, ['number' => $quote->number, 'items' => count($lines), 'buyer_company_id' => $quote->buyer_company_id]);

            return ['resource_type' => 'quote', 'resource_id' => $quote->id, 'response' => ['number' => $quote->number]];
        });

        return Quote::query()->findOrFail($result->resourceId);
    }

    /**
     * @param  list<CartLineQuote>  $lines
     */
    private function record(\App\Data\Ordering\Portal\BuyerActor $actor, array $lines, ?string $message, ?CarbonImmutable $neededBy): Quote
    {
        $now = $this->clock->now();
        $quote = new Quote;
        $quote->forceFill([
            'number' => QuoteNumbering::provisional(),
            'buyer_company_id' => $actor->company->id,
            'requested_by_user_id' => $actor->membership->user_id,
            'requested_by_membership_id' => $actor->membership->id,
            'sales_rep_membership_id' => $actor->company->sales_rep_membership_id,
            'status' => QuoteStatus::Requested,
            'needed_by' => $neededBy,
            'last_activity_at' => $now,
        ])->save();

        $request = new QuoteRequest;
        $request->forceFill([
            'buyer_company_id' => $quote->buyer_company_id,
            'quote_id' => $quote->id,
            'sequence' => 1,
            'kind' => QuoteRequestKind::Initial,
            'message' => $message,
            'author_user_id' => $actor->membership->user_id,
            'author_membership_id' => $actor->membership->id,
            'created_at' => $now,
        ])->save();

        foreach (array_values($lines) as $index => $line) {
            (new QuoteRequestItem)->forceFill([
                'buyer_company_id' => $quote->buyer_company_id,
                'quote_request_id' => $request->id,
                'line_number' => $index + 1,
                'sku_id' => $line->skuId,
                'sku_code' => $line->skuCode,
                'product_name' => $line->productName,
                'variant_label' => $line->variantLabel,
                'unit_code' => $line->unitCode,
                'quantity' => $line->quantity,
                'created_at' => $now,
            ])->save();
        }

        $this->numbering->assign($quote);
        $this->outbox->record('quote.requested', 'quote', $quote->id, [
            'quote_id' => $quote->id, 'number' => $quote->number, 'buyer_company_id' => $quote->buyer_company_id, 'items' => count($lines),
        ]);

        return $quote;
    }

    private function neededBy(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $today = $this->clock->today();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $value, $today->getTimezone()) : null;

        if (! $date instanceof CarbonImmutable || $date->format('Y-m-d') !== $value || $date->lessThan($today) || $date->greaterThan($today->addYear())) {
            throw new InvalidQuote('Informe uma data de necessidade entre hoje e um ano à frente.', ['field' => 'needed_by']);
        }

        return $date;
    }
}
