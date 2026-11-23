<?php

declare(strict_types=1);

namespace Tests\Integration\Quotes;

use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Enums\Quotes\QuoteRequestKind;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Identity\Membership;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteCommercialReview;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteRequest;
use App\Models\Quotes\QuoteRequestItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\Ordering\CheckoutReferences;
use App\Services\Quotes\QuoteVersionFingerprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/**
 * Cotações gravadas direto no contexto de sistema, para testar as
 * proteções do banco sem passar pelas Actions.
 */
final class QuoteFixtures
{
    public static function quote(OrderingScenario $scenario, Membership $requester, string $quantity = '3'): Quote
    {
        return $scenario->system(static function () use ($scenario, $requester, $quantity): Quote {
            $quote = new Quote;
            $quote->forceFill([
                'number' => 'COT-'.Str::upper(Str::random(8)),
                'buyer_company_id' => $requester->buyer_company_id,
                'requested_by_user_id' => $requester->user_id,
                'requested_by_membership_id' => $requester->id,
                'status' => QuoteStatus::Requested,
                'last_activity_at' => now(),
            ])->save();

            $request = new QuoteRequest;
            $request->forceFill([
                'buyer_company_id' => $quote->buyer_company_id,
                'quote_id' => $quote->id,
                'sequence' => 1,
                'kind' => QuoteRequestKind::Initial,
                'author_user_id' => $requester->user_id,
                'author_membership_id' => $requester->id,
            ])->save();

            (new QuoteRequestItem)->forceFill([
                'buyer_company_id' => $quote->buyer_company_id,
                'quote_request_id' => $request->id,
                'line_number' => 1,
                'sku_id' => $scenario->sku->id,
                'sku_code' => $scenario->sku->code,
                'product_name' => $scenario->sku->product->name,
                'unit_code' => 'UN',
                'quantity' => Quantity::of($quantity),
            ])->save();

            return $quote;
        });
    }

    /**
     * @param  array{status?: QuoteVersionStatus, quantity?: string, discount_bps?: int, requires_approval?: bool, hold_until?: CarbonImmutable|null, valid_until?: CarbonImmutable}  $options
     */
    public static function version(OrderingScenario $scenario, Quote $quote, array $options = []): QuoteVersion
    {
        return $scenario->system(static function () use ($scenario, $quote, $options): QuoteVersion {
            $quantity = Quantity::of($options['quantity'] ?? '3');
            $discount = Percentage::ofBasisPoints($options['discount_bps'] ?? 0);
            $price = Money::ofCents((int) DB::table('price_list_items')->where('sku_id', $scenario->sku->id)->value('unit_price_cents'));
            $net = $price->discountedBy($discount);
            $gross = $price->times($quantity);
            $total = $net->times($quantity);
            $term = CheckoutReferences::termSnapshot($scenario->paymentTerm);
            $validUntil = $options['valid_until'] ?? CarbonImmutable::now()->addDays(7);
            $holdUntil = $options['hold_until'] ?? null;
            $requiresApproval = $options['requires_approval'] ?? false;
            $staff = $scenario->tenant->ownerMembership;

            $version = new QuoteVersion;
            $version->forceFill([
                'buyer_company_id' => $quote->buyer_company_id,
                'quote_id' => $quote->id,
                'status' => $options['status'] ?? QuoteVersionStatus::Ready,
                'created_by_user_id' => $staff->user_id,
                'created_by_membership_id' => $staff->id,
                'payment_term_id' => $scenario->paymentTerm->id,
                'payment_term' => $term,
                'valid_until' => $validUntil,
                'stock_hold_until' => $holdUntil,
                'item_count' => 1,
                'subtotal_cents' => $gross,
                'discount_cents' => $gross->minus($total),
                'total_cents' => $total,
                'price_version' => 1,
                'content_fingerprint' => QuoteVersionFingerprint::of([[
                    'sku_id' => $scenario->sku->id, 'quantity' => $quantity->toDatabase(), 'unit_price_cents' => $price->cents,
                    'discount_bps' => $discount->basisPoints, 'line_total_cents' => $total->cents,
                ]], $gross->cents, $total->cents, $term, $validUntil, $holdUntil, null),
                'stock_hold_status' => StockHoldStatus::None,
            ])->save();

            (new QuoteItem)->forceFill([
                'buyer_company_id' => $quote->buyer_company_id,
                'quote_version_id' => $version->id,
                'line_number' => 1,
                'sku_id' => $scenario->sku->id,
                'sku_code' => $scenario->sku->code,
                'product_name' => $scenario->sku->product->name,
                'unit_code' => 'UN',
                'conversion_factor' => '1',
                'packaging_fingerprint' => $scenario->sku->packagingFingerprint(),
                'quantity' => $quantity,
                'base_quantity' => $quantity,
                'unit_price_cents' => $price,
                'discount_bps' => $discount->basisPoints,
                'unit_net_price_cents' => $net,
                'line_gross_cents' => $gross,
                'line_total_cents' => $total,
                'price_source' => ['source' => 'default_list', 'unit_price_cents' => $price->cents],
            ])->save();

            (new QuoteCommercialReview)->forceFill([
                'buyer_company_id' => $quote->buyer_company_id,
                'quote_version_id' => $version->id,
                'evaluated_by_user_id' => $staff->user_id,
                'evaluated_by_membership_id' => $staff->id,
                'author_limit_bps' => 500,
                'max_discount_bps' => $discount->basisPoints,
                'requires_approval' => $requiresApproval,
                'violations' => $requiresApproval ? ['exceeds_role_limit'] : [],
                'lines' => [],
                'justification' => $requiresApproval ? 'Cliente estratégico da região' : null,
            ])->save();

            return $version;
        });
    }

    /**
     * Envia a versão pronta: número seguinte, data de envio e cotação em
     * proposta.
     */
    public static function send(OrderingScenario $scenario, QuoteVersion $version): QuoteVersion
    {
        return $scenario->system(static function () use ($scenario, $version): QuoteVersion {
            $number = (int) QuoteVersion::query()->where('quote_id', $version->quote_id)->max('version_number') + 1;
            // A situação gravada é a de partida: os testes a alteram direto na
            // tabela para exercitar os triggers.
            $version->setRawAttributes([...$version->getAttributes(), 'status' => QuoteVersion::query()->toBase()->where('id', $version->id)->value('status')], true);
            $version->forceFill([
                'status' => QuoteVersionStatus::Sent,
                'version_number' => $number,
                'sent_at' => now(),
                'sent_by_membership_id' => $scenario->tenant->ownerMembership->id,
                'stock_hold_status' => $version->stock_hold_until !== null ? StockHoldStatus::Active : StockHoldStatus::None,
            ])->save();
            Quote::query()->whereKey($version->quote_id)->update(['status' => QuoteStatus::Proposed->value, 'updated_at' => now()]);

            return $version;
        });
    }
}
