<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Defesa em profundidade da aprovação interna (ADR-014, ADR-020): mesmo com
| SQL direto no contexto de comprador da própria empresa, o banco não deixa
| alterar pedido, níveis, decisões nem saldos de orçamento; o histórico de
| decisões e o retrato dos níveis só mudam do jeito previsto; e nenhuma
| linha aponta para registro de outra empresa do mesmo distribuidor.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->tenantId = $this->scenario->tenant->organization->id;
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->center = Fx::costCenter($this->scenario, $this->company);
    $this->budget = Fx::budget($this->scenario, $this->center, 100_000);
    Fx::rule($this->scenario, $this->company, [[2, [$this->ana, $this->bruno]]]);
    $this->order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);
    $this->step = $this->scenario->system(fn () => DB::table('order_approval_steps')->where('order_id', $this->order->id)->first());

    $this->asRequester = fn (Closure $callback): mixed => $this->scenario->tenant->actingAs($this->buyer['membership'], $callback);
    $this->snapshot = fn (): array => $this->scenario->system(fn (): array => [
        'order' => (array) DB::table('orders')->where('id', $this->order->id)->first(['commercial_status', 'approval_status', 'placed_by_membership_id', 'total_cents']),
        'items' => DB::table('order_items')->where('order_id', $this->order->id)->pluck('unit_price_cents')->all(),
        'steps' => DB::table('order_approval_steps')->where('order_id', $this->order->id)->get(['status', 'required_approvals', 'approver_membership_ids'])->map(static fn ($s): array => (array) $s)->all(),
        'decisions' => DB::table('order_approval_decisions')->where('order_id', $this->order->id)->count(),
        'events' => DB::table('order_events')->where('order_id', $this->order->id)->count(),
        'budget' => Fx::budgetState($this->scenario, $this->budget),
        'movements' => DB::table('budget_movements')->where('order_id', $this->order->id)->count(),
    ]);
});

it('não deixa o comprador alterar pedido, itens ou níveis da aprovação pelo banco', function (): void {
    $before = ($this->snapshot)();

    $updated = ($this->asRequester)(fn (): array => [
        DB::table('orders')->where('id', $this->order->id)->update(['approval_status' => 'approved', 'commercial_status' => 'approved']),
        DB::table('orders')->where('id', $this->order->id)->update(['placed_by_membership_id' => $this->ana->id]),
        DB::table('order_items')->where('order_id', $this->order->id)->update(['unit_price_cents' => 1]),
        DB::table('order_approval_steps')->where('order_id', $this->order->id)->update(['status' => 'approved']),
        DB::table('order_approval_steps')->where('order_id', $this->order->id)->update(['required_approvals' => 1, 'approver_membership_ids' => json_encode([$this->ana->id])]),
    ]);

    expect($updated)->toBe([0, 0, 0, 0, 0])
        ->and(fn () => ($this->asRequester)(fn () => DB::table('order_approval_steps')->where('order_id', $this->order->id)->delete()))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(($this->asRequester)(fn (): int => DB::table('order_items')->where('order_id', $this->order->id)->delete()))->toBe(0)
        ->and(fn () => ($this->asRequester)(fn () => DB::table('order_events')->where('order_id', $this->order->id)->delete()))
        ->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => ($this->asRequester)(fn () => DB::table('orders')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'number' => 'PED-9999999',
            'buyer_company_id' => $this->company->id, 'payment_term_id' => $this->scenario->paymentTerm->id,
            'placed_by_user_id' => $this->buyer['membership']->user_id, 'placed_by_membership_id' => $this->buyer['membership']->id,
            'source' => 'cart', 'shipping_address' => '{}', 'payment_term' => '{}',
            'subtotal_cents' => 1, 'discount_cents' => 0, 'freight_cents' => 0, 'total_cents' => 1, 'credit_committed_cents' => 0,
            'commercial_status' => 'confirmed', 'approval_status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => ($this->asRequester)(fn () => DB::table('order_events')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'order_id' => $this->order->id,
            'buyer_company_id' => $this->company->id, 'type' => 'order.approved', 'data' => '{}', 'created_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security')
        ->and(($this->snapshot)())->toBe($before);
});

it('não deixa o comprador registrar decisão em nome de aprovador nem mexer no orçamento pelo banco', function (): void {
    $before = ($this->snapshot)();
    $decision = [
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id,
        'order_id' => $this->order->id, 'order_approval_step_id' => $this->step->id,
        'actor_membership_id' => $this->bruno->id, 'principal_membership_id' => $this->bruno->id,
        'decision' => 'approved', 'order_fingerprint' => str_repeat('a', 64), 'created_at' => now(),
    ];
    $movement = [
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id,
        'cost_center_budget_id' => $this->budget, 'order_id' => $this->order->id,
        'type' => 'release', 'reserved_delta' => -30_000, 'consumed_delta' => 0, 'created_at' => now(),
    ];

    expect(fn () => ($this->asRequester)(fn () => DB::table('order_approval_decisions')->insert($decision)))->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => ($this->asRequester)(fn () => DB::table('budget_movements')->insert($movement)))->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => ($this->asRequester)(fn () => DB::table('budget_movements')->where('order_id', $this->order->id)->delete()))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => ($this->asRequester)(fn () => DB::table('cost_center_budgets')->where('id', $this->budget)->update(['reserved_cents' => 0])))->toThrow(QueryException::class, 'permission denied')
        ->and(($this->snapshot)())->toBe($before);
});

it('torna definitiva a invalidação de uma decisão', function (): void {
    Fx::decide($this->scenario, $this->ana, $this->order);
    $decisionId = $this->scenario->system(fn () => DB::table('order_approval_decisions')->where('order_id', $this->order->id)->value('id'));
    $invalidate = fn (?string $at) => $this->scenario->system(fn () => DB::table('order_approval_decisions')->where('id', $decisionId)->update(['invalidated_at' => $at]));

    expect(fn () => $invalidate(null))->toThrow(QueryException::class, 'já invalidada ou invalidação desfeita');

    $invalidate(now()->toIso8601String());

    expect(fn () => $invalidate(null))->toThrow(QueryException::class, 'já invalidada ou invalidação desfeita')
        ->and(fn () => $invalidate(now()->addDay()->toIso8601String()))->toThrow(QueryException::class, 'já invalidada ou invalidação desfeita')
        ->and(fn () => $this->scenario->system(fn () => DB::table('order_approval_decisions')->where('id', $decisionId)->update(['decision' => 'rejected'])))
        ->toThrow(QueryException::class, 'permission denied');
});

it('mantém o retrato do nível: pedido, número e quórum fixos', function (array $change): void {
    expect(fn () => $this->scenario->system(fn () => DB::table('order_approval_steps')->where('id', $this->step->id)->update($change)))
        ->toThrow(QueryException::class, 'não pode mudar de pedido, número ou quórum');
})->with([
    'quórum' => [['required_approvals' => 1]],
    'número do nível' => [['level_number' => 2]],
]);

it('não move o nível para outro pedido', function (): void {
    $other = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true));

    expect(fn () => $this->scenario->system(fn () => DB::table('order_approval_steps')->where('id', $this->step->id)->update(['order_id' => $other->id])))
        ->toThrow(QueryException::class, 'não pode mudar de pedido, número ou quórum');
});

it('não reabre nível encerrado, rejeitado ou substituído', function (string $terminal): void {
    $steps = fn () => DB::table('order_approval_steps')->where('id', $this->step->id);
    $this->scenario->system(fn () => $steps()->update(['status' => $terminal]));

    expect(fn () => $this->scenario->system(fn () => $steps()->update(['status' => 'pending'])))->toThrow(QueryException::class, 'já encerrado')
        ->and(fn () => $this->scenario->system(fn () => $steps()->update(['status' => 'approved'])))->toThrow(QueryException::class, 'já encerrado');
})->with(['cancelled', 'rejected', 'superseded']);

it('não aceita orçamento, regra ou decisão apontando para registro de outra empresa', function (): void {
    $other = $this->scenario->tenant->addBuyerCompany(['trade_name' => 'Construtora Vale Verde']);
    $otherAdmin = $this->scenario->tenant->addBuyerUser($other, SystemRole::BuyerAdmin);

    // Mesmo no contexto de sistema, que enxerga todas as empresas, as chaves
    // amarram a empresa.
    expect(fn () => $this->scenario->system(fn () => DB::table('cost_center_budgets')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $other->id,
        'cost_center_id' => $this->center->id, 'period_start' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
        'amount_cents' => 0, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'cost_center_budgets_cost_center_id_company_fk');

    expect(fn () => $this->scenario->system(fn () => DB::table('approval_rules')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $other->id,
        'name' => 'Regra com centro alheio', 'cost_center_id' => $this->center->id, 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'approval_rules_cost_center_id_company_fk');

    expect(fn () => $this->scenario->system(fn () => DB::table('orders')->where('id', $this->order->id)->update(['cost_center_id' => Fx::costCenter($this->scenario, $other, 'ADM')->id])))
        ->toThrow(QueryException::class, 'orders_cost_center_id_company_fk');

    // O titular precisa ser da empresa do pedido e decisão em nome de outro
    // só por delegação desse titular para esse ator.
    expect(fn () => $this->scenario->system(fn () => DB::table('order_approval_decisions')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id,
        'order_id' => $this->order->id, 'order_approval_step_id' => $this->step->id,
        'actor_membership_id' => $otherAdmin->id, 'principal_membership_id' => $otherAdmin->id,
        'decision' => 'approved', 'order_fingerprint' => str_repeat('a', 64), 'created_at' => now(),
    ])))->toThrow(QueryException::class, '_membership_id_company_fk')
        ->and(fn () => $this->scenario->system(fn () => DB::table('order_approval_decisions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id,
            'order_id' => $this->order->id, 'order_approval_step_id' => $this->step->id,
            'actor_membership_id' => $this->ana->id, 'principal_membership_id' => $this->bruno->id,
            'decision' => 'approved', 'order_fingerprint' => str_repeat('a', 64), 'created_at' => now(),
        ])))->toThrow(QueryException::class, 'order_approval_decisions_delegation_check');

    $carla = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $otherPair = Fx::delegation($this->scenario, $carla, $this->ana);

    expect(fn () => $this->scenario->system(fn () => DB::table('order_approval_decisions')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id,
        'order_id' => $this->order->id, 'order_approval_step_id' => $this->step->id,
        'actor_membership_id' => $this->ana->id, 'principal_membership_id' => $this->bruno->id,
        'delegation_id' => $otherPair->id, 'decision' => 'approved', 'order_fingerprint' => str_repeat('a', 64), 'created_at' => now(),
    ])))->toThrow(QueryException::class, 'order_approval_decisions_delegation_pair_fk');
});

it('não deixa o comprador alterar regras, delegações ou orçamentos pelo banco, nem como administrador', function (): void {
    $admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $delegation = Fx::delegation($this->scenario, $this->ana, $this->bruno);
    $ruleId = $this->scenario->system(fn () => DB::table('approval_rules')->where('buyer_company_id', $this->company->id)->value('id'));
    $asAdmin = fn (Closure $callback): mixed => $this->scenario->tenant->actingAs($admin, $callback);
    $state = fn (): array => $this->scenario->system(fn (): array => [
        'rules' => DB::table('approval_rules')->where('buyer_company_id', $this->company->id)->get(['id', 'is_active', 'priority'])->map(static fn ($r): array => (array) $r)->all(),
        'levels' => DB::table('approval_rule_levels')->where('buyer_company_id', $this->company->id)->get(['required_approvals'])->map(static fn ($r): array => (array) $r)->all(),
        'approvers' => DB::table('approval_rule_level_approvers')->where('buyer_company_id', $this->company->id)->count(),
        'delegation' => (array) DB::table('approval_delegations')->where('id', $delegation->id)->first(['delegate_membership_id', 'valid_until', 'revoked_at']),
        'budget' => DB::table('cost_center_budgets')->where('id', $this->budget)->value('amount_cents'),
    ]);
    $before = $state();

    expect($asAdmin(fn (): array => [
        DB::table('approval_rules')->where('id', $ruleId)->update(['is_active' => false, 'priority' => 999]),
        DB::table('approval_rule_levels')->where('buyer_company_id', $this->company->id)->update(['required_approvals' => 1]),
        DB::table('approval_rule_level_approvers')->where('buyer_company_id', $this->company->id)->delete(),
        DB::table('approval_delegations')->where('id', $delegation->id)->update(['revoked_at' => now()]),
        DB::table('cost_center_budgets')->where('id', $this->budget)->update(['amount_cents' => 999_999_999]),
    ]))->toBe([0, 0, 0, 0, 0])
        ->and(fn () => $asAdmin(fn () => DB::table('approval_rules')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id,
            'name' => 'Regra paralela', 'priority' => 1000, 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => $asAdmin(fn () => DB::table('approval_delegations')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id,
            'delegator_membership_id' => $this->bruno->id, 'delegate_membership_id' => $admin->id,
            'valid_from' => now()->subDay(), 'valid_until' => now()->addDay(), 'reason' => 'Delegação forjada',
            'created_by_user_id' => $admin->user_id, 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security')
        ->and($state())->toBe($before);
});

it('torna definitiva a revogação da delegação e fixa titular, delegado e prazo', function (): void {
    $delegation = Fx::delegation($this->scenario, $this->ana, $this->bruno);
    $update = fn (array $values) => $this->scenario->system(fn () => DB::table('approval_delegations')->where('id', $delegation->id)->update($values));

    expect(fn () => $update(['delegate_membership_id' => $this->buyer['membership']->id]))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => $update(['valid_until' => now()->addYear()]))->toThrow(QueryException::class, 'permission denied');

    $update(['revoked_at' => now()]);

    expect(fn () => $update(['revoked_at' => null]))->toThrow(QueryException::class, 'já revogada')
        ->and(fn () => $update(['revoked_at' => now()->addDay()]))->toThrow(QueryException::class, 'já revogada');
});

it('não mostra a outra empresa os níveis, decisões e movimentos de orçamento', function (): void {
    Fx::decide($this->scenario, $this->ana, $this->order);
    $other = $this->scenario->tenant->addBuyerCompany(['trade_name' => 'Construtora Vale Verde']);
    $otherAdmin = $this->scenario->tenant->addBuyerUser($other, SystemRole::BuyerAdmin);

    $seen = $this->scenario->tenant->actingAs($otherAdmin, static fn (): array => [
        DB::table('order_approval_steps')->count(),
        DB::table('order_approval_decisions')->count(),
        DB::table('budget_movements')->count(),
        DB::table('cost_center_budgets')->count(),
    ]);

    expect($seen)->toBe([0, 0, 0, 0])
        ->and(($this->asRequester)(static fn (): array => [
            DB::table('order_approval_steps')->count(),
            DB::table('order_approval_decisions')->count(),
            DB::table('budget_movements')->count(),
        ]))->toBe([1, 1, 1]);
});
