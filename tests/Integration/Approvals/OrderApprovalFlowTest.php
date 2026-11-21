<?php

declare(strict_types=1);

use App\Actions\Approvals\ReassignApprovalStep;
use App\Actions\Approvals\RetryOrderConfirmation;
use App\Actions\Ordering\CancelOrderItems;
use App\Domain\Approvals\Exceptions\ApprovalNotAllowed;
use App\Domain\Approvals\Exceptions\BudgetExceeded;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalOutcome;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Aprovação interna de compras (RF-050, RN-050 a RN-056): submissão,
| quórum por nível, rejeição, delegação, perda de acesso, reavaliação,
| confirmação com estoque e crédito, orçamento e cancelamento.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->carla = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->center = Fx::costCenter($this->scenario, $this->company);
});

it('envia para aprovação sem reservar estoque, com orçamento reservado e número definitivo', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]], ['min_total_cents' => 20_000]);
    $budget = Fx::budget($this->scenario, $this->center, 100_000);

    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);
    $stored = Fx::fresh($this->scenario, $order);

    expect($stored->commercial_status)->toBe(CommercialStatus::PendingApproval)
        ->and($stored->approval_status)->toBe(ApprovalStatus::Pending)
        ->and($stored->number)->toStartWith('PED-')
        ->and($stored->approval_rule_name)->toBe('Compras acima do limite')
        ->and($stored->approvalSteps)->toHaveCount(1)
        ->and($stored->approvalSteps[0]->approver_membership_ids)->toBe([$this->ana->id])
        ->and(Fx::reservations($this->scenario, $order))->toBe(0)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 30_000, 'consumed' => 0])
        ->and(Fx::events($this->scenario, $order))->toBe(['order.submitted_for_approval'])
        ->and($this->scenario->system(static fn (): int => (int) DB::table('tenant_usage_counters')->sum('orders_confirmed')))->toBe(0);
});

it('confirma direto quando nenhuma regra se aplica', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]], ['min_total_cents' => 50_000]);

    $order = Fx::fresh($this->scenario, Fx::place($this->scenario, $this->buyer));

    expect($order->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and($order->approval_status)->toBe(ApprovalStatus::NotRequired)
        ->and(Fx::reservations($this->scenario, $order))->toBe(1);
});

it('exige quórum por nível e confirma após o último nível, consumindo o orçamento', function (): void {
    // Solicitante com permissão de aprovar e listado como aprovador do nível.
    $this->scenario->system(fn () => Membership::query()->whereKey($this->buyer['membership']->id)
        ->update(['role_id' => $this->scenario->tenant->role(SystemRole::BuyerAdmin)->id]));
    Fx::rule($this->scenario, $this->company, [[2, [$this->ana, $this->bruno, $this->buyer['membership']]], [1, [$this->carla]]]);
    $budget = Fx::budget($this->scenario, $this->center, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    expect(fn () => Fx::decide($this->scenario, $this->buyer['membership'], $order))->toThrow(ApprovalNotAllowed::class, 'Quem solicitou');
    expect(Fx::decide($this->scenario, $this->ana, $order))->toBe(ApprovalOutcome::Pending)
        ->and(fn () => Fx::decide($this->scenario, $this->ana, $order))->toThrow(ApprovalNotAllowed::class, 'já registrou')
        ->and(fn () => Fx::decide($this->scenario, $this->carla, $order))->toThrow(ApprovalNotAllowed::class, 'não é aprovador');
    expect(Fx::decide($this->scenario, $this->bruno, $order))->toBe(ApprovalOutcome::Pending)
        ->and(Fx::fresh($this->scenario, $order)->approvalSteps->pluck('status')->all())->toBe([ApprovalStepStatus::Approved, ApprovalStepStatus::Pending]);

    expect(Fx::decide($this->scenario, $this->carla, $order))->toBe(ApprovalOutcome::Confirmed);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and($stored->approval_status)->toBe(ApprovalStatus::Approved)
        ->and(Fx::reservations($this->scenario, $order))->toBe(1)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 30_000])
        ->and(Fx::events($this->scenario, $order))->toBe([
            'order.submitted_for_approval', 'order.approval_recorded', 'order.approval_level_approved', 'order.approved', 'order.confirmed',
        ]);
});

it('encerra na rejeição com motivo e libera o orçamento', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $budget = Fx::budget($this->scenario, $this->center, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    expect(fn () => Fx::decide($this->scenario, $this->ana, $order, ApprovalDecision::Rejected))->toThrow(ApprovalNotAllowed::class, 'motivo');
    expect(Fx::decide($this->scenario, $this->ana, $order, ApprovalDecision::Rejected, 'Fora do orçamento do trimestre'))->toBe(ApprovalOutcome::Rejected);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::Rejected)
        ->and($stored->approval_status)->toBe(ApprovalStatus::Rejected)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 0]);
});

it('aceita decisão do delegado vigente em nome do titular', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $davi = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $order = Fx::place($this->scenario, $this->buyer);
    $delegate = fn (Membership $to, string $from, string $until): mixed => $this->scenario->system(fn () => (new ApprovalDelegation)->forceFill([
        'buyer_company_id' => $this->company->id, 'delegator_membership_id' => $this->ana->id, 'delegate_membership_id' => $to->id,
        'valid_from' => $from, 'valid_until' => $until, 'reason' => 'Férias da titular', 'created_by_user_id' => $this->ana->user_id,
    ])->save());

    $delegate($davi, now()->subDays(10)->toDateTimeString(), now()->subDay()->toDateTimeString());
    expect(fn () => Fx::decide($this->scenario, $davi, $order))->toThrow(ApprovalNotAllowed::class, 'não é aprovador');

    $delegate($davi, now()->subDay()->toDateTimeString(), now()->addDays(5)->toDateTimeString());
    expect(Fx::decide($this->scenario, $davi, $order))->toBe(ApprovalOutcome::Confirmed);

    $decision = $this->scenario->system(fn () => DB::table('order_approval_decisions')->where('order_id', $order->id)->first());
    expect($decision->actor_membership_id)->toBe($davi->id)
        ->and($decision->principal_membership_id)->toBe($this->ana->id)
        ->and($decision->delegation_id)->not->toBeNull();
});

it('não deixa aprovador revogado decidir e permite reatribuir o nível bloqueado', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $earlyAdmin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);

    // Antes da revogação o nível não está bloqueado: a reatribuição é recusada.
    expect(fn () => $this->scenario->tenant->actingAs($earlyAdmin, fn () => app(ReassignApprovalStep::class)->handle($order, [$this->bruno->id], 'Troca de aprovador')))
        ->toThrow(ApprovalNotAllowed::class, 'apenas para níveis bloqueados');
    $this->scenario->system(fn () => Membership::query()->whereKey($this->ana->id)->update([
        'status' => MembershipStatus::Revoked->value, 'revoked_at' => now(),
    ]));
    $admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);

    expect(fn () => Fx::decide($this->scenario, $this->ana, $order))->toThrow(AuthorizationException::class);
    expect(fn () => $this->scenario->tenant->actingAs($this->bruno, fn () => app(ReassignApprovalStep::class)->handle($order, [$this->bruno->id], 'Aprovadora desligada')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->scenario->tenant->actingAs($admin, fn () => app(ReassignApprovalStep::class)->handle($order, [$this->buyer['membership']->id], 'Aprovadora desligada')))
        ->toThrow(ApprovalNotAllowed::class);

    $this->scenario->tenant->actingAs($admin, fn () => app(ReassignApprovalStep::class)->handle($order, [$this->bruno->id], 'Aprovadora desligada'));

    expect(Fx::decide($this->scenario, $this->bruno, $order))->toBe(ApprovalOutcome::Confirmed);
});

it('invalida as aprovações e reprecifica quando o preço muda antes da confirmação', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [1, [$this->bruno]]]);
    $budget = Fx::budget($this->scenario, $this->center, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);
    Fx::decide($this->scenario, $this->ana, $order);

    $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->update(['unit_price_cents' => 12_000]));
    $this->scenario->system(static fn () => app(App\Services\Pricing\PriceVersion::class)->bump());

    expect(Fx::decide($this->scenario, $this->bruno, $order))->toBe(ApprovalOutcome::Invalidated);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::PendingApproval)
        ->and($stored->total_cents->cents)->toBe(36_000)
        ->and($stored->approvalSteps->pluck('status')->all())->toBe([ApprovalStepStatus::Pending, ApprovalStepStatus::Pending])
        ->and($this->scenario->system(fn (): int => DB::table('order_approval_decisions')->where('order_id', $order->id)->whereNull('invalidated_at')->count()))->toBe(0)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 36_000, 'consumed' => 0]);

    Fx::decide($this->scenario, $this->ana, $order);
    expect(Fx::decide($this->scenario, $this->bruno, $order))->toBe(ApprovalOutcome::Confirmed);
});

it('mantém aprovado sem confirmar quando falta estoque e confirma na nova tentativa', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $this->scenario->system(fn () => app(App\Services\Inventory\StockLedger::class)->adjust($this->scenario->warehouse->id, $this->scenario->sku->id, App\Domain\Shared\Quantity::of('-49'), 'Quebra no depósito'));

    expect(Fx::decide($this->scenario, $this->ana, $order))->toBe(ApprovalOutcome::AwaitingConfirmation);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::Approved)
        ->and($stored->confirmation_issue)->toContain('Estoque insuficiente')
        ->and(Fx::reservations($this->scenario, $order))->toBe(0);

    $this->scenario->system(fn () => app(App\Services\Inventory\StockLedger::class)->receive($this->scenario->warehouse->id, $this->scenario->sku->id, App\Domain\Shared\Quantity::of('10'), 'Reposição'));

    expect($this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(RetryOrderConfirmation::class)->handle($order)))->toBe(ApprovalOutcome::Confirmed)
        ->and(Fx::fresh($this->scenario, $order)->confirmation_issue)->toBeNull();
});

it('recusa a submissão quando o orçamento do mês não comporta o pedido', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    Fx::budget($this->scenario, $this->center, 25_000);

    expect(fn () => Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]))->toThrow(BudgetExceeded::class);
    expect($this->scenario->system(static fn (): int => DB::table('orders')->count()))->toBe(0);
});

it('cancela pedido aguardando aprovação liberando o orçamento', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $budget = Fx::budget($this->scenario, $this->center, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(CancelOrderItems::class)->handle($order, null, 'Compra feita por engano'));

    expect(Fx::fresh($this->scenario, $order)->commercial_status)->toBe(CommercialStatus::Cancelled)
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 0])
        ->and(fn () => Fx::decide($this->scenario, $this->ana, $order))->toThrow(App\Domain\Approvals\Exceptions\OrderNotAwaitingApproval::class);
});

it('usa a regra de maior prioridade e restringe a decisão à própria empresa', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]], [], priority: 1, name: 'Regra geral');
    Fx::rule($this->scenario, $this->company, [[1, [$this->bruno]]], ['min_total_cents' => 10_000], priority: 5, name: 'Regra de valor');
    $order = Fx::place($this->scenario, $this->buyer);
    $outsider = $this->scenario->tenant->addBuyerUser($this->scenario->tenant->addBuyerCompany(), SystemRole::Approver);

    expect(Fx::fresh($this->scenario, $order)->approval_rule_name)->toBe('Regra de valor')
        ->and(fn () => Fx::decide($this->scenario, $outsider, $order))->toThrow(AuthorizationException::class)
        ->and(fn () => Fx::decide($this->scenario, $this->ana, $order))->toThrow(ApprovalNotAllowed::class);
});

it('não deixa comprador sem visão de todos os pedidos cancelar ou confirmar o pedido de um colega', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $colleague = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Purchaser);
    $this->scenario->system(function () use ($colleague): void {
        $role = new App\Models\Identity\Role;
        $role->forceFill([
            'key' => 'comprador_restrito', 'name' => 'Comprador restrito', 'kind' => App\Enums\Identity\MembershipKind::Buyer,
            'permissions' => [App\Enums\Identity\Permission::PortalCart->value, App\Enums\Identity\Permission::PortalOrdersPlace->value],
            'max_discount_bps' => 0, 'is_system' => false,
        ])->save();
        Membership::query()->whereKey($colleague->id)->update(['role_id' => $role->id]);
    });

    expect(fn () => $this->scenario->tenant->actingAs($colleague, fn () => app(CancelOrderItems::class)->handle($order, null, 'Cancelamento indevido')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->scenario->tenant->actingAs($colleague, fn () => app(RetryOrderConfirmation::class)->handle($order)))
        ->toThrow(AuthorizationException::class);
});

it('reserva no orçamento do mês no fuso comercial', function (): void {
    app()->instance(App\Support\Clock\Clock::class, App\Support\Clock\FrozenClock::at('2026-12-01T02:30:00Z'));
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $november = Fx::budget($this->scenario, $this->center, 100_000, '2026-11-15');
    $december = Fx::budget($this->scenario, $this->center, 100_000, '2026-12-15');

    Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    expect(Fx::budgetState($this->scenario, $november))->toBe(['reserved' => 30_000, 'consumed' => 0])
        ->and(Fx::budgetState($this->scenario, $december))->toBe(['reserved' => 0, 'consumed' => 0]);
});

it('volta para aprovação sem reserva quando o novo total não cabe no orçamento', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $budget = Fx::budget($this->scenario, $this->center, 32_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->update(['unit_price_cents' => 12_000]));
    $this->scenario->system(static fn () => app(App\Services\Pricing\PriceVersion::class)->bump());

    expect(Fx::decide($this->scenario, $this->ana, $order))->toBe(ApprovalOutcome::Invalidated);

    $stored = Fx::fresh($this->scenario, $order);
    expect($stored->commercial_status)->toBe(CommercialStatus::PendingApproval)
        ->and($stored->total_cents->cents)->toBe(36_000)
        ->and($stored->confirmation_issue)->toContain('orçamento')
        ->and(Fx::budgetState($this->scenario, $budget))->toBe(['reserved' => 0, 'consumed' => 0]);
});

it('permite à equipe cancelar pedido aguardando aprovação', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    $this->scenario->tenant->actingAs($manager, fn () => app(CancelOrderItems::class)->handle($order, null, 'Solicitado pelo cliente por telefone'));

    expect(Fx::fresh($this->scenario, $order)->commercial_status)->toBe(CommercialStatus::Cancelled);
});
