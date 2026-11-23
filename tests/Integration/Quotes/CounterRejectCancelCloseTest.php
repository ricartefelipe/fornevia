<?php

declare(strict_types=1);

use App\Actions\Quotes\Portal\CancelQuoteRequest;
use App\Actions\Quotes\Portal\CounterQuoteProposal;
use App\Actions\Quotes\Portal\RejectQuote;
use App\Actions\Quotes\Staff\CloseQuote;
use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteRequestKind;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Models\Quotes\QuoteRequest;
use App\Models\Quotes\QuoteVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;

/*
| Respostas do comprador (pedido de revisão, recusa, cancelamento) e
| encerramento pela equipe, com liberação da reserva em cada caminho.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->quote = F::request($this->scenario, $this->buyer);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->hold = now((string) config('app.business_timezone'))->addDays(2)->toDateString();
    $this->asBuyer = fn (Closure $callback): mixed => $this->scenario->tenant->actingAs($this->buyer['membership'], $callback);
    $this->reserved = fn (): string => (string) $this->scenario->system(fn () => DB::table('stock_levels')->where('sku_id', $this->scenario->sku->id)->value('reserved'));
});

it('pede revisão com novas quantidades, encerra a reserva e volta para a equipe', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => $this->hold]);
    $seen = ReviewedQuoteVersion::of($version, withHold: true);

    expect(fn () => ($this->asBuyer)(fn () => app(CounterQuoteProposal::class)->handle($this->quote->id, $seen, 'Mais', [1 => '5'], (string) Str::uuid7())))
        ->toThrow(InvalidQuote::class, 'Explique')
        ->and(fn () => ($this->asBuyer)(fn () => app(CounterQuoteProposal::class)->handle($this->quote->id, $seen, 'Sem nenhum item', [1 => '0'], (string) Str::uuid7())))
        ->toThrow(InvalidQuote::class, 'ao menos um item');

    ($this->asBuyer)(fn () => app(CounterQuoteProposal::class)->handle($this->quote->id, $seen, 'Precisamos de 5 unidades com o mesmo preço', [1 => '5'], (string) Str::uuid7()));

    $quote = F::fresh($this->scenario, $this->quote);
    $request = $this->scenario->system(fn () => QuoteRequest::query()->with('items')->where('kind', QuoteRequestKind::Counteroffer->value)->firstOrFail());

    expect($quote->status)->toBe(QuoteStatus::Requested)
        ->and($quote->versions->first()->status)->toBe(QuoteVersionStatus::Countered)
        ->and($quote->versions->first()->stock_hold_end_reason)->toBe(StockHoldEndReason::Countered)
        ->and(($this->reserved)())->toBe('0.000')
        ->and($request->responds_to_version_id)->toBe($version->id)
        ->and($request->items[0]->quantity->toDatabase())->toBe('5.000')
        ->and(fn () => ($this->asBuyer)(fn () => app(CounterQuoteProposal::class)->handle($this->quote->id, $seen, 'De novo a mesma coisa', [], (string) Str::uuid7())))
        ->toThrow(QuoteNotOpen::class);
});

it('recusa a proposta exibida com motivo e encerra a cotação', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => $this->hold]);

    expect(fn () => ($this->asBuyer)(fn () => app(RejectQuote::class)->handle($this->quote->id, ReviewedQuoteVersion::of($version, withHold: true), 'other')))
        ->toThrow(InvalidQuote::class, 'Descreva')
        // Tela que mostrava a proposta sem reserva: não vale para a reserva ativa.
        ->and(fn () => ($this->asBuyer)(fn () => app(RejectQuote::class)->handle($this->quote->id, new ReviewedQuoteVersion($version->id, $version->content_fingerprint, false), 'price')))
        ->toThrow(QuoteVersionOutdated::class);

    ($this->asBuyer)(fn () => app(RejectQuote::class)->handle($this->quote->id, ReviewedQuoteVersion::of($version, withHold: true), 'price'));
    $quote = F::fresh($this->scenario, $this->quote);

    expect($quote->status)->toBe(QuoteStatus::Rejected)
        ->and($quote->close_reason_code?->value)->toBe('price')
        ->and($quote->closed_by_user_id)->toBe($this->buyer['membership']->user_id)
        ->and($quote->versions->first()->status)->toBe(QuoteVersionStatus::Rejected)
        ->and(($this->reserved)())->toBe('0.000');
});

it('cancela a solicitação sem proposta e retira a versão em preparo', function (): void {
    $rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $this->scenario->system(fn () => DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => $rep->id]));
    F::submit($this->scenario, $rep, $this->quote, discount: '10', overrides: ['justification' => 'Concorrente cobriu o preço']);

    ($this->asBuyer)(fn () => app(CancelQuoteRequest::class)->handle($this->quote->id, 'not_needed'));
    $quote = F::fresh($this->scenario, $this->quote);

    expect($quote->status)->toBe(QuoteStatus::Cancelled)
        ->and($quote->versions->first()->status)->toBe(QuoteVersionStatus::Withdrawn);
});

it('não cancela pelo comprador cotação com proposta vigente', function (): void {
    F::submit($this->scenario, $this->manager, $this->quote);

    expect(fn () => ($this->asBuyer)(fn () => app(CancelQuoteRequest::class)->handle($this->quote->id, 'not_needed')))->toThrow(QuoteNotOpen::class, 'recuse-a');
});

it('encerra pela equipe com motivo, retirando a proposta e liberando a reserva', function (): void {
    F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => $this->hold]);

    expect(fn () => $this->scenario->tenant->actingAs($this->manager, fn () => app(CloseQuote::class)->handle($this->quote->id, 'x')))->toThrow(InvalidQuote::class);

    $this->scenario->tenant->actingAs($this->manager, fn () => app(CloseQuote::class)->handle($this->quote->id, 'Cliente fechou com outro fornecedor'));
    $quote = F::fresh($this->scenario, $this->quote);

    expect($quote->status)->toBe(QuoteStatus::Cancelled)
        ->and($quote->close_reason_code?->value)->toBe('staff_closed')
        ->and($quote->versions->first()->status)->toBe(QuoteVersionStatus::Withdrawn)
        ->and($quote->versions->first()->stock_hold_end_reason)->toBe(StockHoldEndReason::Cancelled)
        ->and(($this->reserved)())->toBe('0.000')
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], static fn (): int => QuoteVersion::query()->count()))->toBe(1);
});
