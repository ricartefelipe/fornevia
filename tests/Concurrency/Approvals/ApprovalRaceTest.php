<?php

declare(strict_types=1);

use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Domain\Approvals\Exceptions\BudgetExceeded;
use App\Domain\Approvals\Exceptions\OrderNotAwaitingApproval;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CommercialStatus;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
 * Aprovação sob concorrência, com processos separados.
 *
 * O pedido é bloqueado antes de cada decisão: o segundo aprovador espera o
 * primeiro e enxerga a decisão dele, e a aprovação final que concorre com o
 * cancelamento enxerga o pedido cancelado. A decisão por delegação bloqueia
 * a delegação: a revogação concorrente vale antes da decisão. O orçamento é
 * bloqueado antes da reserva: a segunda submissão enxerga a reserva da
 * primeira (RN-055).
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
        // Espera no pedido, e não apenas no nível: o lock do pedido é o que
        // serializa decisão, cancelamento, nova tentativa e reatribuição.
        $harness->waitUntilBlockedOnRow('aprovador-bruno', 'orders');
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

it('não confirma pedido cancelado enquanto a aprovação final esperava o lock', function (): void {
    $scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $buyer = $scenario->buyerWithCart(quantity: '2', sameCompany: true);
    $ana = $scenario->tenant->addBuyerUser($buyer['company'], SystemRole::Approver);
    Fx::rule($scenario, $buyer['company'], [[1, [$ana]]]);
    $center = Fx::costCenter($scenario, $buyer['company']);
    $budget = Fx::budget($scenario, $center, 100_000);
    $order = Fx::place($scenario, $buyer, ['costCenterId' => $center->id]);
    $args = static fn ($member, array $extra = []): array => [
        'tenant_id' => $scenario->tenant->organization->id, 'user_id' => $member->user_id, 'membership_id' => $member->id,
        'buyer_company_id' => $buyer['company']->id, 'order_id' => $order->id, ...$extra,
    ];

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('cancelamento-segura-commit');

    try {
        $cancel = $harness->start('cancel-order', $args($buyer['membership'], ['hold' => 'cancelamento-segura-commit']), 'solicitante-cancela');
        $harness->waitUntilWaitingAt('cancelamento-segura-commit', 1);
        $approve = $harness->start('decide-approval', $args($ana), 'aprovadora-ana');
        $harness->waitUntilBlockedOnRow('aprovadora-ana', 'orders');
        $hold->release();
        [$rc, $ra] = [$cancel->result(), $approve->result()];
    } finally {
        $harness->shutdown();
    }

    expect($rc)->toBe(['ok' => true, 'data' => ['status' => 'cancelled']])
        ->and($ra['ok'])->toBeFalse()
        ->and($ra['error'])->toBe(OrderNotAwaitingApproval::class)
        ->and(Fx::reservations($scenario, $order))->toBe(0)
        ->and(Fx::budgetState($scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 0])
        ->and(Fx::events($scenario, $order))->not->toContain('order.confirmed')
        ->and($scenario->system(static fn (): int => DB::table('order_approval_decisions')->count()))->toBe(0);
});

it('não aceita decisão por delegação revogada enquanto a decisão esperava', function (): void {
    $scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $buyer = $scenario->buyerWithCart(quantity: '2', sameCompany: true);
    $ana = $scenario->tenant->addBuyerUser($buyer['company'], SystemRole::Approver);
    $davi = $scenario->tenant->addBuyerUser($buyer['company'], SystemRole::Approver);
    Fx::rule($scenario, $buyer['company'], [[1, [$ana]]]);
    $delegation = Fx::delegation($scenario, $ana, $davi);
    $order = Fx::place($scenario, $buyer);
    $args = static fn ($member, array $extra = []): array => [
        'tenant_id' => $scenario->tenant->organization->id, 'user_id' => $member->user_id, 'membership_id' => $member->id,
        'buyer_company_id' => $buyer['company']->id, 'order_id' => $order->id, ...$extra,
    ];

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('revogacao-segura-commit');

    try {
        $revoke = $harness->start('revoke-delegation', $args($ana, ['delegation_id' => $delegation->id, 'hold' => 'revogacao-segura-commit']), 'titular-revoga');
        $harness->waitUntilWaitingAt('revogacao-segura-commit', 1);
        $decide = $harness->start('decide-approval', $args($davi), 'delegado-decide');
        $harness->waitUntilBlockedOnRow('delegado-decide', 'approval_delegations');
        $hold->release();
        [$rr, $rd] = [$revoke->result(), $decide->result()];
    } finally {
        $harness->shutdown();
    }

    expect($rr)->toBe(['ok' => true, 'data' => ['revoked' => true]])
        ->and($rd['ok'])->toBeFalse()
        ->and($rd['error'])->toBe(ApprovalNotAllowed::class)
        ->and($scenario->system(static fn (): int => DB::table('order_approval_decisions')->count()))->toBe(0)
        ->and(Fx::fresh($scenario, $order)->commercial_status)->toBe(CommercialStatus::PendingApproval);
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
