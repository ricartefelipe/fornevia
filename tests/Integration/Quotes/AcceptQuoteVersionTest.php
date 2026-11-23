<?php

declare(strict_types=1);

use App\Actions\Approvals\RetryOrderConfirmation;
use App\Actions\Ordering\CancelOrderItems;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Credit\Exceptions\CreditNotApproved;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteAlreadyAccepted;
use App\Domain\Quotes\Exceptions\QuoteExpired;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CommercialStatus;
use App\Enums\Ordering\OrderSource;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;

/*
| Aceite que gera o pedido (RN-040, RN-042, RN-044) e pedido de cotação na
| aprovação interna: preços mantidos, reserva transferida ou liberada.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->quote = F::request($this->scenario, $this->buyer);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->hold = now((string) config('app.business_timezone'))->addDays(2)->toDateString();
    $this->reserved = fn (): string => (string) $this->scenario->system(fn () => DB::table('stock_levels')->where('sku_id', $this->scenario->sku->id)->value('reserved'));
    $this->items = fn (Order $order) => $this->scenario->system(fn () => OrderItem::query()->where('order_id', $order->id)->get());
});

it('gera o pedido com os valores da versão mesmo depois de mudar a tabela, o nome e o custo', function (): void {
    F::submit($this->scenario, $this->manager, $this->quote, discount: '10', overrides: ['justification' => 'Volume fechado para a obra', 'selfApprove' => true]);
    $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->update(['unit_price_cents' => 15_000]));
    $this->scenario->system(static fn () => app(App\Services\Pricing\PriceVersion::class)->bump());
    $this->scenario->system(fn () => DB::table('products')->where('id', $this->scenario->sku->product_id)->update(['name' => 'Nome novo']));

    $order = F::accept($this->scenario, $this->buyer, $this->quote);
    $item = ($this->items)($order)->first();
    $quote = F::fresh($this->scenario, $this->quote);

    expect($order->source)->toBe(OrderSource::Quote)
        ->and($order->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and($order->total_cents->cents)->toBe(27_000)
        ->and($item->unit_price_cents->cents)->toBe(10_000)
        ->and($item->discount_bps)->toBe(1_000)
        ->and($item->product_name)->toBe('Disjuntor bipolar 32A')
        ->and($item->price_source['quote']['number'])->toBe($quote->number)
        ->and($item->price_source)->not->toHaveKey('min_unit_price_cents')
        ->and($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->versions->first()->status)->toBe(QuoteVersionStatus::Accepted)
        ->and($quote->versions->first()->accepted_by_membership_id)->toBe($this->buyer['membership']->id)
        ->and(Fx::events($this->scenario, $order))->toContain('order.created_from_quote');
});

it('transfere a reserva da cotação ao pedido confirmado', function (): void {
    F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => $this->hold]);

    expect(($this->reserved)())->toBe('3.000');

    $order = F::accept($this->scenario, $this->buyer, $this->quote);
    $version = F::fresh($this->scenario, $this->quote)->versions->first();

    expect(($this->reserved)())->toBe('3.000')
        ->and(Fx::reservations($this->scenario, $order))->toBe(1)
        ->and($version->stock_hold_status)->toBe(StockHoldStatus::Ended)
        ->and($version->stock_hold_end_reason)->toBe(StockHoldEndReason::Transferred);
});

it('desfaz tudo quando a confirmação recusa e deixa a proposta aceitável', function (): void {
    $scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000, creditLimitCents: 1_000, onCredit: true);
    $buyer = $scenario->buyerWithCart(quantity: '3');
    $quote = F::request($scenario, $buyer);
    F::submit($scenario, $scenario->tenant->addStaff(SystemRole::SalesManager), $quote);

    expect(fn () => F::accept($scenario, $buyer, $quote))->toThrow(CreditNotApproved::class)
        ->and(F::fresh($scenario, $quote)->status)->toBe(QuoteStatus::Proposed)
        ->and(F::fresh($scenario, $quote)->versions->first()->status)->toBe(QuoteVersionStatus::Sent)
        ->and($scenario->system(static fn (): int => Order::query()->count()))->toBe(0);
});

it('recusa aceite de versão desatualizada, vencida, com reserva encerrada ou repetido', function (): void {
    $first = F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => $this->hold]);
    $seenFirst = ReviewedQuoteVersion::of($first, withHold: true);
    F::submit($this->scenario, $this->manager, $this->quote, discount: '2');

    expect(fn () => F::accept($this->scenario, $this->buyer, $this->quote, $seenFirst))->toThrow(QuoteVersionOutdated::class, 'versão 2');

    $seen = $this->scenario->system(fn () => ReviewedQuoteVersion::of(F::fresh($this->scenario, $this->quote)->versions->firstWhere('status', QuoteVersionStatus::Sent), withHold: true));
    $order = F::accept($this->scenario, $this->buyer, $this->quote, $seen);

    // Segundo aceite (outra aba, outra chave): informa o pedido já gerado.
    expect(fn () => F::accept($this->scenario, $this->buyer, $this->quote, $seen))->toThrow(QuoteAlreadyAccepted::class, $order->number);

    $other = F::request($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true));
    F::submit($this->scenario, $this->manager, $other);
    app()->instance(Clock::class, new FrozenClock(now()->addDays(9)->toImmutable()));

    expect(fn () => F::accept($this->scenario, $this->buyer, $other))->toThrow(QuoteExpired::class);
});

it('recusa aceite com condição de pagamento alterada, por quem não faz pedidos e de outra empresa', function (): void {
    F::submit($this->scenario, $this->manager, $this->quote);
    $requester = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Requester);

    expect(fn () => F::accept($this->scenario, ['membership' => $requester, 'address' => $this->buyer['address']], $this->quote))->toThrow(AuthorizationException::class);

    $outsider = $this->scenario->buyerWithCart(quantity: '1');

    expect(fn () => F::accept($this->scenario, $outsider, $this->quote))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->scenario->system(fn () => DB::table('payment_terms')->where('id', $this->scenario->paymentTerm->id)->update(['installment_days' => json_encode([15])]));

    expect(fn () => F::accept($this->scenario, $this->buyer, $this->quote))->toThrow(InvalidQuote::class, 'condição de pagamento');
});

it('não reprecifica o pedido de cotação na aprovação interna e mantém a reserva até confirmar', function (): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    F::submit($this->scenario, $this->manager, $this->quote, discount: '10', overrides: ['justification' => 'Volume fechado para a obra', 'selfApprove' => true, 'stockHoldUntilDay' => $this->hold]);

    $order = F::accept($this->scenario, $this->buyer, $this->quote);

    expect($order->commercial_status)->toBe(CommercialStatus::PendingApproval)
        ->and(($this->reserved)())->toBe('3.000');

    $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->update(['unit_price_cents' => 12_000]));
    $this->scenario->system(static fn () => app(App\Services\Pricing\PriceVersion::class)->bump());

    expect(Fx::decide($this->scenario, $ana, $order))->toBe(ApprovalOutcome::Confirmed);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->total_cents->cents)->toBe(27_000)
        ->and(($this->items)($order)->first()->discount_bps)->toBe(1_000)
        ->and(($this->reserved)())->toBe('3.000')
        ->and(F::fresh($this->scenario, $this->quote)->versions->first()->stock_hold_end_reason)->toBe(StockHoldEndReason::Transferred);
});

it('libera a reserva quando o pedido de cotação é rejeitado ou cancelado antes de confirmar', function (string $case): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => $this->hold]);
    $order = F::accept($this->scenario, $this->buyer, $this->quote);

    $case === 'rejeitado'
        ? Fx::decide($this->scenario, $ana, $order, App\Enums\Approvals\ApprovalDecision::Rejected, 'Fora do planejamento')
        : $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(CancelOrderItems::class)->handle($order, null, 'Compra feita por engano'));

    $version = F::fresh($this->scenario, $this->quote)->versions->first();

    expect(($this->reserved)())->toBe('0.000')
        ->and($version->stock_hold_end_reason)->toBe($case === 'rejeitado' ? StockHoldEndReason::OrderRejected : StockHoldEndReason::OrderCancelled)
        ->and(F::fresh($this->scenario, $this->quote)->status)->toBe(QuoteStatus::Accepted);
})->with(['rejeitado', 'cancelado']);

it('mantém o pedido aguardando quando a condição muda antes da confirmação', function (): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    F::submit($this->scenario, $this->manager, $this->quote);
    $order = F::accept($this->scenario, $this->buyer, $this->quote);
    $this->scenario->system(fn () => DB::table('payment_terms')->where('id', $this->scenario->paymentTerm->id)->update(['installment_days' => json_encode([15])]));

    expect(Fx::decide($this->scenario, $ana, $order))->toBe(ApprovalOutcome::AwaitingConfirmation)
        ->and(Fx::fresh($this->scenario, $order)->confirmation_issue)->toContain('solicite nova cotação')
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(RetryOrderConfirmation::class)->handle($order)))->toBe(ApprovalOutcome::AwaitingConfirmation);
});

it('mantém o pedido aguardando quando a embalagem do item muda antes da confirmação', function (): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    F::submit($this->scenario, $this->manager, $this->quote);
    $order = F::accept($this->scenario, $this->buyer, $this->quote);
    // O preço negociado valia para a embalagem da proposta.
    $this->scenario->system(fn () => DB::table('skus')->where('id', $this->scenario->sku->id)->update(['conversion_factor' => '12']));

    expect(Fx::decide($this->scenario, $ana, $order))->toBe(ApprovalOutcome::AwaitingConfirmation)
        ->and(Fx::fresh($this->scenario, $order)->confirmation_issue)->toContain('embalagem do item '.$this->scenario->sku->code);
});
