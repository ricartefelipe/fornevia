<?php

declare(strict_types=1);

namespace Tests\Integration\Quotes;

use App\Actions\Quotes\Portal\AcceptQuoteVersion;
use App\Actions\Quotes\Portal\RequestQuote;
use App\Actions\Quotes\Staff\SubmitQuoteVersion;
use App\Data\Quotes\AcceptQuoteData;
use App\Data\Quotes\QuoteLineInput;
use App\Data\Quotes\QuoteVersionData;
use App\Data\Quotes\RequestQuoteData;
use App\Data\Quotes\ReviewedQuoteState;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteVersion;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/**
 * Fluxo de cotação pelas Actions, para os testes de integração.
 */
final class QuoteFlow
{
    /**
     * @param  array{membership: Membership, cart: \App\Models\Ordering\Cart}  $buyer
     */
    public static function request(OrderingScenario $scenario, array $buyer): Quote
    {
        return $scenario->tenant->actingAs($buyer['membership'], static fn (): Quote => app(RequestQuote::class)->handle(new RequestQuoteData($buyer['cart']->id, (string) Str::uuid7())));
    }

    public static function state(OrderingScenario $scenario, Quote $quote): ReviewedQuoteState
    {
        return $scenario->system(static fn (): ReviewedQuoteState => ReviewedQuoteState::of(QuoteVersion::query()->where('quote_id', $quote->id)->get()));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function submit(OrderingScenario $scenario, Membership $staff, Quote $quote, string $discount = '0', string $quantity = '3', array $overrides = []): QuoteVersion
    {
        $state = $overrides['reviewed'] ?? self::state($scenario, $quote);
        unset($overrides['reviewed']);
        $data = new QuoteVersionData(...[
            'quoteId' => $quote->id,
            'reviewed' => $state,
            'idempotencyKey' => (string) Str::uuid7(),
            'lines' => [new QuoteLineInput($scenario->sku->id, Quantity::of($quantity), Percentage::parse($discount))],
            'paymentTermId' => $scenario->paymentTerm->id,
            'validUntilDay' => now((string) config('app.business_timezone'))->addDays(7)->toDateString(),
            ...$overrides,
        ]);

        return $scenario->tenant->actingAs($staff, static fn (): QuoteVersion => app(SubmitQuoteVersion::class)->handle($data));
    }

    /**
     * Aceita a versão enviada como o comprador a vê (com a situação da
     * reserva).
     *
     * @param  array{membership: Membership, address: \App\Models\Buyers\BuyerAddress}  $buyer
     * @param  array<string, mixed>  $overrides
     */
    public static function accept(OrderingScenario $scenario, array $buyer, Quote $quote, ?ReviewedQuoteVersion $reviewed = null, array $overrides = []): Order
    {
        $reviewed ??= $scenario->system(static fn (): ReviewedQuoteVersion => ReviewedQuoteVersion::of(
            QuoteVersion::query()->where('quote_id', $quote->id)->where('status', 'sent')->firstOrFail(),
            withHold: true,
        ));

        return $scenario->tenant->actingAs($buyer['membership'], static fn (): Order => app(AcceptQuoteVersion::class)->handle($quote->id, new AcceptQuoteData(...[
            'reviewed' => $reviewed,
            'idempotencyKey' => (string) Str::uuid7(),
            'shippingAddressId' => $buyer['address']->id,
            ...$overrides,
        ])));
    }

    public static function fresh(OrderingScenario $scenario, Quote $quote): Quote
    {
        return $scenario->system(static fn (): Quote => Quote::query()->with(['versions.items', 'versions.review'])->findOrFail($quote->id));
    }
}
