<?php

declare(strict_types=1);

use App\Actions\Quotes\System\ExpireDueQuotes;
use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Enums\Quotes\StockHoldStatus;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;

/*
| Expiração agendada de propostas e reservas (ADR-025).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->tz = (string) config('app.business_timezone');
    $this->reserved = fn (): string => (string) $this->scenario->system(fn () => DB::table('stock_levels')->where('sku_id', $this->scenario->sku->id)->value('reserved'));
    $this->travel = fn (string $interval) => app()->instance(Clock::class, new FrozenClock(now()->add($interval)->toImmutable()));
});

it('vence a proposta enviada, libera a reserva e não age duas vezes', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote, overrides: [
        'validUntilDay' => now($this->tz)->addDay()->toDateString(),
        'stockHoldUntilDay' => now($this->tz)->addDay()->toDateString(),
    ]);

    expect(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 0, 'holds_released' => 0, 'skipped' => 0]);

    ($this->travel)('P3D');

    expect(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 1, 'holds_released' => 1, 'skipped' => 0])
        ->and(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 0, 'holds_released' => 0, 'skipped' => 0]);

    $stored = F::fresh($this->scenario, $quote);
    expect($stored->status)->toBe(QuoteStatus::Expired)
        ->and($stored->versions->first()->status)->toBe(QuoteVersionStatus::Expired)
        ->and($stored->versions->first()->stock_hold_end_reason)->toBe(StockHoldEndReason::Expired)
        ->and(($this->reserved)())->toBe('0.000');
});

it('libera só a reserva vencida e mantém a proposta válida', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote, overrides: ['stockHoldUntilDay' => now($this->tz)->toDateString()]);
    ($this->travel)('P1D');

    expect(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 0, 'holds_released' => 1, 'skipped' => 0]);

    $stored = F::fresh($this->scenario, $quote);
    expect($stored->status)->toBe(QuoteStatus::Proposed)
        ->and($stored->versions->first()->stock_hold_status)->toBe(StockHoldStatus::Ended);
});

it('libera a reserva de versão aceita com pedido aguardando aprovação', function (): void {
    $ana = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $this->buyer['company'], [[1, [$ana]]]);
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote, overrides: ['stockHoldUntilDay' => now($this->tz)->toDateString()]);
    $order = F::accept($this->scenario, $this->buyer, $quote);
    ($this->travel)('P1D');

    expect(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 0, 'holds_released' => 1, 'skipped' => 0])
        ->and(($this->reserved)())->toBe('0.000');

    // A confirmação reserva do disponível, como no carrinho.
    expect(Fx::decide($this->scenario, $ana, $order))->toBe(App\Enums\Approvals\ApprovalOutcome::Confirmed)
        ->and(($this->reserved)())->toBe('3.000');
});

it('vence a versão em preparo e processa vários distribuidores', function (): void {
    $rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $this->scenario->system(fn () => DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => $rep->id]));
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $rep, $quote, discount: '10', overrides: ['justification' => 'Concorrente cobriu o preço', 'validUntilDay' => now($this->tz)->toDateString()]);

    $other = OrderingScenario::create(stock: '5');
    $otherQuote = F::request($other, $other->buyerWithCart(quantity: '1'));
    F::submit($other, $other->tenant->addStaff(SystemRole::SalesManager), $otherQuote, quantity: '1', overrides: ['validUntilDay' => now($this->tz)->toDateString()]);
    ($this->travel)('P1D');

    expect(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 2, 'holds_released' => 0, 'skipped' => 0])
        ->and(F::fresh($this->scenario, $quote)->status)->toBe(QuoteStatus::Requested)
        ->and(F::fresh($other, $otherQuote)->status)->toBe(QuoteStatus::Expired);
});

it('segue com as demais cotações quando uma falha', function (): void {
    $broken = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $broken, overrides: ['validUntilDay' => now($this->tz)->toDateString()]);
    $healthy = F::request($this->scenario, $this->scenario->buyerWithCart(quantity: '2', sameCompany: true));
    F::submit($this->scenario, $this->manager, $healthy, quantity: '2', overrides: ['validUntilDay' => now($this->tz)->toDateString()]);
    ($this->travel)('P1D');
    App\Models\Quotes\QuoteVersion::updating(static function (App\Models\Quotes\QuoteVersion $version) use ($broken): void {
        if ($version->quote_id === $broken->id) {
            throw new RuntimeException('falha simulada');
        }
    });

    expect(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 1, 'holds_released' => 0, 'skipped' => 1])
        ->and(F::fresh($this->scenario, $healthy)->status)->toBe(QuoteStatus::Expired)
        ->and(F::fresh($this->scenario, $broken)->status)->toBe(QuoteStatus::Proposed);
});

it('está agendada a cada minuto', function (): void {
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->map(static fn ($e): string => (string) $e->command);

    expect($events->contains(static fn (string $c): bool => str_contains($c, 'quotes:expire')))->toBeTrue();
    $this->artisan('quotes:expire')->expectsOutputToContain('Versões vencidas: 0')->assertSuccessful();
});
