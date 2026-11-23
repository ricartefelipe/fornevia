<?php

declare(strict_types=1);

use App\Actions\Quotes\System\ExpireDueQuotes;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Quotes\Exceptions\QuoteAlreadyAccepted;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Identity\SystemRole;
use App\Models\Ordering\Order;
use App\Models\Quotes\QuoteVersion;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Cotações sob concorrência, com processos separados. Aceite, nova versão
 * e expiração bloqueiam a cotação primeiro (ADR-006): quem espera enxerga o
 * resultado de quem terminou antes. A reserva com prazo disputa o estoque
 * com o checkout sob o lock dos saldos.
 */

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '3', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->quote = F::request($this->scenario, $this->buyer);
    $this->tz = (string) config('app.business_timezone');
    $this->accept = fn (QuoteVersion $v, array $extra = []): array => [
        'tenant_id' => $this->scenario->tenant->organization->id, 'user_id' => $this->buyer['membership']->user_id,
        'membership_id' => $this->buyer['membership']->id, 'buyer_company_id' => $this->buyer['company']->id,
        'quote_id' => $this->quote->id, 'version_id' => $v->id, 'fingerprint' => $v->content_fingerprint,
        'hold_active' => $v->stock_hold_status === App\Enums\Quotes\StockHoldStatus::Active,
        'shipping_address_id' => $this->buyer['address']->id, 'idempotency_key' => (string) Str::uuid7(), ...$extra,
    ];
    $this->submit = fn (QuoteVersion $current, array $extra = []): array => [
        'tenant_id' => $this->scenario->tenant->organization->id, 'user_id' => $this->manager->user_id, 'membership_id' => $this->manager->id,
        'quote_id' => $this->quote->id, 'with_buyer_version_id' => $current->id, 'idempotency_key' => (string) Str::uuid7(),
        'sku_id' => $this->scenario->sku->id, 'quantity' => '3', 'discount' => '2', 'payment_term_id' => $this->scenario->paymentTerm->id,
        'valid_day' => now($this->tz)->addDays(5)->toDateString(), ...$extra,
    ];
    $this->orders = fn (): int => $this->scenario->system(static fn (): int => Order::query()->count());
});

it('aceite segurando o commit faz a nova versão concorrente encontrar a cotação encerrada', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote);
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('aceite-segura-commit');

    try {
        $accept = $harness->start('accept-quote', ($this->accept)($version, ['hold' => 'aceite-segura-commit']), 'comprador-aceita');
        $harness->waitUntilWaitingAt('aceite-segura-commit', 1);
        $submit = $harness->start('submit-quote-version', ($this->submit)($version), 'equipe-nova-versao');
        $harness->waitUntilBlockedOnRow('equipe-nova-versao', 'quotes');
        $hold->release();
        [$ra, $rs] = [$accept->result(), $submit->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra['ok'])->toBeTrue()
        ->and($rs['ok'])->toBeFalse()
        ->and($rs['error'])->toBe(QuoteNotOpen::class)
        ->and(($this->orders)())->toBe(1);
});

it('nova versão segurando o commit faz o aceite concorrente recusar a versão antiga', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote);
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('versao-segura-commit');

    try {
        $submit = $harness->start('submit-quote-version', ($this->submit)($version, ['hold' => 'versao-segura-commit']), 'equipe-nova-versao');
        $harness->waitUntilWaitingAt('versao-segura-commit', 1);
        $accept = $harness->start('accept-quote', ($this->accept)($version), 'comprador-aceita');
        $harness->waitUntilBlockedOnRow('comprador-aceita', 'quotes');
        $hold->release();
        [$rs, $ra] = [$submit->result(), $accept->result()];
    } finally {
        $harness->shutdown();
    }

    expect($rs['ok'])->toBeTrue()
        ->and($ra['ok'])->toBeFalse()
        ->and($ra['error'])->toBe(QuoteVersionOutdated::class)
        ->and(($this->orders)())->toBe(0);
});

it('dois aceites com chaves diferentes geram um único pedido', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote);
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('primeiro-aceite');

    try {
        $first = $harness->start('accept-quote', ($this->accept)($version, ['hold' => 'primeiro-aceite']), 'aba-um');
        $harness->waitUntilWaitingAt('primeiro-aceite', 1);
        $second = $harness->start('accept-quote', ($this->accept)($version), 'aba-dois');
        $harness->waitUntilBlockedOnRow('aba-dois', 'quotes');
        $hold->release();
        [$r1, $r2] = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    expect($r1['ok'])->toBeTrue()
        ->and($r2['ok'])->toBeFalse()
        ->and($r2['error'])->toBe(QuoteAlreadyAccepted::class)
        ->and(($this->orders)())->toBe(1)
        ->and($this->scenario->system(static fn (): int => DB::table('outbox_messages')->where('type', 'order.confirmed')->count()))->toBe(1);
});

it('a expiração pula a cotação ocupada por um aceite em andamento', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote, overrides: ['validUntilDay' => now($this->tz)->toDateString()]);
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('aceite-antes-do-vencimento');

    try {
        $accept = $harness->start('accept-quote', ($this->accept)($version, ['hold' => 'aceite-antes-do-vencimento']), 'comprador-aceita');
        $harness->waitUntilWaitingAt('aceite-antes-do-vencimento', 1);
        app()->instance(Clock::class, new FrozenClock(now()->addDay()->toImmutable()));
        $expired = app(ExpireDueQuotes::class)->handle();
        $hold->release();
        $ra = $accept->result();
    } finally {
        $harness->shutdown();
    }

    expect($expired)->toBe(['expired' => 0, 'holds_released' => 0, 'skipped' => 1])
        ->and($ra['ok'])->toBeTrue()
        ->and(app(ExpireDueQuotes::class)->handle())->toBe(['expired' => 0, 'holds_released' => 0, 'skipped' => 0]);
});

it('reserva da proposta e checkout disputando a última unidade: só um obtém', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote);
    $checkout = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('reserva-segura-commit');

    try {
        $reserve = $harness->start('submit-quote-version', ($this->submit)($version, ['hold' => 'reserva-segura-commit', 'hold_day' => now($this->tz)->addDay()->toDateString()]), 'equipe-reserva');
        $harness->waitUntilWaitingAt('reserva-segura-commit', 1);
        $order = $harness->start('place-order', $this->scenario->workerArgs($checkout), 'checkout');
        $harness->waitUntilBlockedOnRow('checkout', 'stock_levels');
        $hold->release();
        [$rr, $ro] = [$reserve->result(), $order->result()];
    } finally {
        $harness->shutdown();
    }

    expect($rr['ok'])->toBeTrue()
        ->and($ro['ok'])->toBeFalse()
        ->and($ro['error'])->toBe(InsufficientStock::class)
        ->and((string) $this->scenario->system(fn () => DB::table('stock_levels')->where('sku_id', $this->scenario->sku->id)->value('reserved')))->toBe('3.000');
});
