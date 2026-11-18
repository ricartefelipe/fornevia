<?php

declare(strict_types=1);

use App\Domain\Credit\Exceptions\CreditNotApproved;
use App\Enums\Identity\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Redução de limite concorrente com o checkout de um pedido a prazo. A
 * alteração de limite e o comprometimento de crédito bloqueiam a mesma
 * conta (FOR UPDATE): o pedido nunca é aprovado com o limite anterior a
 * uma redução já gravada, e a redução enxerga o comprometimento de um
 * pedido confirmado antes dela.
 *
 * Limite de R$ 1.500,00; pedido de R$ 1.000,00; redução para R$ 500,00.
 */

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '100', unitPriceCents: 10_000, creditLimitCents: 150_000, onCredit: true);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '10');
    $this->finance = $this->scenario->tenant->addStaff(SystemRole::Finance);

    $this->limitArgs = fn (int $limitCents, array $overrides = []): array => [
        'tenant_id' => $this->scenario->tenant->organization->id,
        'actor_user_id' => $this->finance->user_id,
        'actor_membership_id' => $this->finance->id,
        'buyer_company_id' => $this->buyer['company']->id,
        'limit_cents' => $limitCents,
        'reason' => 'Revisão de risco após atraso de pagamento',
        ...$overrides,
    ];

    $this->state = fn (): array => $this->scenario->system(static fn (): array => [
        'limit' => (int) DB::table('credit_accounts')->value('limit_cents'),
        'committed' => (int) DB::table('credit_accounts')->value('committed_cents'),
        'movements' => DB::table('credit_movements')->count(),
        'orders' => DB::table('orders')->count(),
        'reserved' => (string) DB::table('stock_levels')->value('reserved'),
        'changes' => DB::table('credit_limit_changes')
            ->orderBy('created_at')
            ->get(['previous_limit_cents', 'new_limit_cents'])
            ->map(static fn (object $row): array => [(int) $row->previous_limit_cents, (int) $row->new_limit_cents])
            ->all(),
    ]);
});

it('recusa o pedido que espera uma redução de limite em andamento', function (): void {
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('reducao-segura');

    try {
        $reduction = $harness->start('change-credit-limit', ($this->limitArgs)(50_000, ['hold' => 'reducao-segura']), 'reducao');
        $harness->waitUntilWaitingAt('reducao-segura', 1);
        $checkout = $harness->start('place-order', $this->scenario->workerArgs($this->buyer), 'pedido');
        $harness->waitUntilBlockedOnLock('pedido');
        $hold->release();
        [$rr, $rc] = [$reduction->result(), $checkout->result()];
    } finally {
        $harness->shutdown();
    }

    expect($rr['ok'])->toBeTrue()
        ->and($rr['data'] ?? null)->toBe(['limit_cents' => 50_000, 'committed_cents' => 0, 'exposure_cents' => 0, 'available_cents' => 50_000])
        ->and($rc['ok'])->toBeFalse()
        ->and($rc['error'])->toBe(CreditNotApproved::class);

    // O pedido recusado não deixa efeito parcial (pedido, crédito ou reserva).
    expect(($this->state)())->toBe([
        'limit' => 50_000, 'committed' => 0, 'movements' => 0, 'orders' => 0, 'reserved' => '0.000',
        'changes' => [[150_000, 50_000]],
    ]);
});

it('aplica a redução depois do pedido confirmado antes dela, enxergando o comprometimento', function (): void {
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('pedido-segura');

    try {
        $checkout = $harness->start('place-order', $this->scenario->workerArgs($this->buyer, ['hold' => 'pedido-segura']), 'pedido');
        $harness->waitUntilWaitingAt('pedido-segura', 1);
        $reduction = $harness->start('change-credit-limit', ($this->limitArgs)(50_000), 'reducao');
        $harness->waitUntilBlockedOnLock('reducao');
        $hold->release();
        [$rc, $rr] = [$checkout->result(), $reduction->result()];
    } finally {
        $harness->shutdown();
    }

    expect($rc['ok'])->toBeTrue()
        ->and($rr['ok'])->toBeTrue()
        ->and($rr['data'] ?? null)->toBe(['limit_cents' => 50_000, 'committed_cents' => 100_000, 'exposure_cents' => 100_000, 'available_cents' => -50_000]);

    expect(($this->state)())->toBe([
        'limit' => 50_000, 'committed' => 100_000, 'movements' => 1, 'orders' => 1, 'reserved' => '10.000',
        'changes' => [[150_000, 50_000]],
    ]);
});

it('encadeia o histórico de duas alterações de limite simultâneas, sem perder a anterior', function (): void {
    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('primeira-segura');

    try {
        $first = $harness->start('change-credit-limit', ($this->limitArgs)(100_000, ['hold' => 'primeira-segura']), 'primeira');
        $harness->waitUntilWaitingAt('primeira-segura', 1);
        $second = $harness->start('change-credit-limit', ($this->limitArgs)(80_000), 'segunda');
        $harness->waitUntilBlockedOnLock('segunda');
        $hold->release();
        [$r1, $r2] = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    expect($r1['ok'])->toBeTrue()->and($r2['ok'])->toBeTrue()
        ->and(($this->state)()['changes'])->toBe([[150_000, 100_000], [100_000, 80_000]])
        ->and(($this->state)()['limit'])->toBe(80_000);
});
