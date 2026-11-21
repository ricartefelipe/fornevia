<?php

declare(strict_types=1);

use App\Domain\Approvals\Exceptions\BudgetExceeded;
use App\Enums\Identity\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Aprovação sob concorrência, com processos separados.
 *
 * O pedido é bloqueado antes de cada decisão: o segundo aprovador espera o
 * primeiro e enxerga a decisão dele. O orçamento é bloqueado antes da
 * reserva: a segunda submissão enxerga a reserva da primeira (RN-055).
 */
it('confirma uma única vez quando duas aprovações simultâneas completam o quórum', function (): void {
    $scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $buyer = $scenario->buyerWithCart(quantity: '2', sameCompany: true);
    $ana = $scenario->tenant->addBuyerUser($buyer['company'], SystemRole::Approver);
    $bruno = $scenario->tenant->addBuyerUser($buyer['company'], SystemRole::Approver);
    Fx::rule($scenario, $buyer['company'], [[2, [$ana, $bruno]]]);
    $order = Fx::place($scenario, $buyer);
    $args = static fn ($member, array $extra = []): array => [
        'tenant_id' => $scenario->tenant->organization->id, 'user_id' => $member->user_id, 'membership_id' => $member->id,
        'buyer_company_id' => $buyer['company']->id, 'order_id' => $order->id, ...$extra,
    ];

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('ana-segura-commit');

    try {
        $first = $harness->start('decide-approval', $args($ana, ['hold' => 'ana-segura-commit']), 'aprovadora-ana');
        $harness->waitUntilWaitingAt('ana-segura-commit', 1);
        $second = $harness->start('decide-approval', $args($bruno), 'aprovador-bruno');
        $harness->waitUntilBlockedOnLock('aprovador-bruno');
        $hold->release();
        [$ra, $rb] = [$first->result(), $second->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra)->toBe(['ok' => true, 'data' => ['outcome' => 'pending']])
        ->and($rb)->toBe(['ok' => true, 'data' => ['outcome' => 'confirmed']])
        ->and(Fx::reservations($scenario, $order))->toBe(1)
        ->and(array_count_values(Fx::events($scenario, $order))['order.confirmed'] ?? 0)->toBe(1);
});

it('não deixa duas submissões simultâneas ultrapassarem o orçamento do centro de custo', function (): void {
    $scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $first = $scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $second = $scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $ana = $scenario->tenant->addBuyerUser($first['company'], SystemRole::Approver);
    Fx::rule($scenario, $first['company'], [[1, [$ana]]]);
    $center = Fx::costCenter($scenario, $first['company']);
    $budget = Fx::budget($scenario, $center, 50_000);

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('primeira-segura-commit');

    try {
        $a = $harness->start('place-order', $scenario->workerArgs($first, ['hold' => 'primeira-segura-commit', 'cost_center_id' => $center->id]), 'submissao-a');
        $harness->waitUntilWaitingAt('primeira-segura-commit', 1);
        $b = $harness->start('place-order', $scenario->workerArgs($second, ['cost_center_id' => $center->id]), 'submissao-b');
        $harness->waitUntilBlockedOnLock('submissao-b');
        $hold->release();
        [$ra, $rb] = [$a->result(), $b->result()];
    } finally {
        $harness->shutdown();
    }

    expect($ra['ok'])->toBeTrue()
        ->and($rb['ok'])->toBeFalse()
        ->and($rb['error'])->toBe(BudgetExceeded::class)
        ->and(Fx::budgetState($scenario, $budget))->toBe(['reserved' => 30_000, 'consumed' => 0])
        ->and($scenario->system(static fn (): int => DB::table('orders')->count()))->toBe(1);
});
