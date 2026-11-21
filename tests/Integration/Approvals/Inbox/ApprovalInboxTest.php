<?php

declare(strict_types=1);

use App\Actions\Approvals\Queries\InboxEntry;
use App\Actions\Approvals\Queries\InboxTab;
use App\Actions\Approvals\ReassignApprovalStep;
use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Approvals\ApprovalStepStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Approvals\Inbox\InboxFixtures as Inbox;
use Tests\Support\Builders\OrderingScenario;

/*
| Consultas da fila de aprovação (RF-050, RN-050 a RN-053): quem vê o quê
| (aprovador do nível pendente, delegado vigente, solicitante, outra
| empresa, outro tenant, aprovador revogado), decididos, bloqueados,
| paginação e detalhe com níveis, decisões e histórico.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->carla = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->davi = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
});

it('mostra o pedido ao aprovador do nível pendente e ao delegado vigente, não ao aprovador do nível seguinte', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [1, [$this->bruno]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Inbox::delegate($this->scenario, $this->ana, $this->davi);
    Inbox::delegate($this->scenario, $this->ana, $this->carla, '-10 days', '-1 day');

    $forAna = Inbox::overview($this->scenario, $this->ana);
    $forDavi = Inbox::overview($this->scenario, $this->davi);

    expect(array_map(static fn (InboxEntry $e): string => $e->order->number, $forAna->entries->items()))->toBe([$order->number])
        ->and($forAna->counts)->toBe(['pendentes' => 1, 'decididos' => 0])
        ->and($forAna->entries->items()[0]->principalName)->toBeNull()
        ->and($forDavi->entries->items()[0]->order->number)->toBe($order->number)
        ->and($forDavi->entries->items()[0]->principalName)->toBe(Inbox::name($this->ana))
        ->and(Inbox::numbers($this->scenario, $this->bruno))->toBe([])
        ->and(Inbox::numbers($this->scenario, $this->carla))->toBe([]);

    // Aprovador do nível seguinte vê o detalhe, mas ainda não decide.
    $review = Inbox::review($this->scenario, $this->bruno, $order->id);
    expect($review->canDecide())->toBeFalse()
        ->and($review->current?->unavailableReason)->toContain('não é aprovador deste nível');

    Fx::decide($this->scenario, $this->ana, $order, ApprovalDecision::Approved, 'De acordo');

    expect(Inbox::numbers($this->scenario, $this->ana))->toBe([])
        ->and(Inbox::numbers($this->scenario, $this->ana, InboxTab::Decided))->toBe([$order->number])
        ->and(Inbox::numbers($this->scenario, $this->davi))->toBe([])
        ->and(Inbox::numbers($this->scenario, $this->bruno))->toBe([$order->number]);

    $decided = Inbox::overview($this->scenario, $this->ana, InboxTab::Decided)->entries->items()[0];
    expect($decided->decision?->decision)->toBe(ApprovalDecision::Approved)
        ->and($decided->decision?->level)->toBe(1)
        ->and($decided->decision?->comment)->toBe('De acordo')
        ->and($decided->decision?->invalidated)->toBeFalse();
});

it('recusa a fila a quem não aprova e não lista ao solicitante o próprio pedido', function (): void {
    $this->scenario->system(fn () => Membership::query()->whereKey($this->buyer['membership']->id)
        ->update(['role_id' => $this->scenario->tenant->role(SystemRole::BuyerAdmin)->id]));
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana, $this->buyer['membership']]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $purchaser = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Purchaser);

    expect(fn () => Inbox::overview($this->scenario, $purchaser))->toThrow(AuthorizationException::class)
        ->and(Inbox::numbers($this->scenario, $this->buyer['membership']))->toBe([])
        ->and(Inbox::numbers($this->scenario, $this->ana))->toBe([$order->number]);

    $review = Inbox::review($this->scenario, $this->buyer['membership'], $order->id);
    expect($review->canDecide())->toBeFalse()
        ->and($review->current?->unavailableReason)->toBe('Quem solicitou a compra não pode aprová-la.')
        ->and(array_column($review->steps[0]->approvers, 'situation', 'id'))->toEqual([
            $this->ana->id => 'Aguardando decisão',
            $this->buyer['membership']->id => 'Solicitante: não conta para o quórum',
        ]);
});

it('isola os pedidos de outra empresa e de outro tenant', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    $otherBuyer = $this->scenario->buyerWithCart(quantity: '1');
    $outsider = $this->scenario->tenant->addBuyerUser($otherBuyer['company'], SystemRole::Approver);
    Fx::rule($this->scenario, $otherBuyer['company'], [[1, [$outsider]]]);
    $otherOrder = Fx::place($this->scenario, $otherBuyer);

    $foreign = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $foreignBuyer = $foreign->buyerWithCart(quantity: '1');
    $foreignApprover = $foreign->tenant->addBuyerUser($foreignBuyer['company'], SystemRole::Approver);
    Fx::rule($foreign, $foreignBuyer['company'], [[1, [$foreignApprover]]]);
    $foreignOrder = Fx::place($foreign, $foreignBuyer);

    expect(Inbox::numbers($this->scenario, $this->ana))->toBe([$order->number])
        ->and(Inbox::numbers($this->scenario, $outsider))->toBe([$otherOrder->number])
        ->and(Inbox::numbers($foreign, $foreignApprover))->toBe([$foreignOrder->number])
        ->and(Inbox::numbers($this->scenario, $this->admin, InboxTab::Blocked))->toBe([])
        ->and(fn () => Inbox::review($this->scenario, $this->ana, $otherOrder->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Inbox::review($this->scenario, $this->admin, $otherOrder->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Inbox::review($this->scenario, $this->ana, $foreignOrder->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Inbox::review($foreign, $foreignApprover, $order->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Inbox::review($this->scenario, $this->ana, $order->number))->toThrow(ModelNotFoundException::class);
});

it('mostra o detalhe só a quem participa da aprovação ou administra as regras', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $direct = $this->scenario->buyerWithCart(quantity: '1', sameCompany: true);
    $this->scenario->system(fn () => App\Models\Approvals\ApprovalRule::query()->update(['is_active' => false]));
    $withoutApproval = Fx::place($this->scenario, $direct);

    expect(Inbox::review($this->scenario, $this->ana, $order->id)->order->id)->toBe($order->id)
        ->and(Inbox::review($this->scenario, $this->admin, $order->id)->order->id)->toBe($order->id)
        ->and(fn () => Inbox::review($this->scenario, $this->carla, $order->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => Inbox::review($this->scenario, $this->admin, $withoutApproval->id))->toThrow(ModelNotFoundException::class);

    Inbox::delegate($this->scenario, $this->ana, $this->carla);

    expect(Inbox::review($this->scenario, $this->carla, $order->id)->principalName)->toBe(Inbox::name($this->ana));
});

it('tira o aprovador revogado da fila, inclusive dos delegados, e bloqueia o nível para a administração', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Inbox::delegate($this->scenario, $this->ana, $this->davi);

    expect(Inbox::numbers($this->scenario, $this->davi))->toBe([$order->number])
        ->and(Inbox::numbers($this->scenario, $this->admin, InboxTab::Blocked))->toBe([]);

    Inbox::revoke($this->scenario, $this->ana);

    $blocked = Inbox::overview($this->scenario, $this->admin, InboxTab::Blocked);

    expect(fn () => Inbox::overview($this->scenario, $this->ana))->toThrow(AuthorizationException::class)
        ->and(Inbox::numbers($this->scenario, $this->davi))->toBe([])
        ->and(Inbox::numbers($this->scenario, $this->admin))->toBe([])
        ->and($blocked->counts)->toBe(['pendentes' => 0, 'decididos' => 0, 'bloqueados' => 1])
        ->and($blocked->entries->items()[0]->order->number)->toBe($order->number)
        ->and($blocked->entries->items()[0]->pending?->blocked)->toBeTrue();

    $review = Inbox::review($this->scenario, $this->admin, $order->id);
    expect($review->blocked())->toBeTrue()
        ->and($review->canReassign)->toBeTrue()
        ->and(array_keys($review->candidates))->toEqualCanonicalizing([$this->bruno->id, $this->carla->id, $this->davi->id, $this->admin->id])
        ->and($review->suggestedApprovers)->toBe([])
        ->and($review->steps[0]->approvers[0]['situation'])->toBe('Sem acesso para aprovar');

    // Quem não administra regras vê o bloqueio, mas não pode reatribuir.
    Inbox::delegate($this->scenario, $this->ana, $this->bruno);
    expect(Inbox::review($this->scenario, $this->bruno, $order->id)->canReassign)->toBeFalse()
        ->and(fn () => Inbox::overview($this->scenario, $this->bruno, InboxTab::Blocked))->toThrow(AuthorizationException::class);

    $stepId = Fx::reviewed($this->scenario, $order)->stepId;
    $this->scenario->tenant->actingAs($this->admin, fn () => app(ReassignApprovalStep::class)->handle($order, [$this->carla->id], 'Aprovadora desligada', $stepId));

    expect(Inbox::numbers($this->scenario, $this->admin, InboxTab::Blocked))->toBe([])
        ->and(Inbox::numbers($this->scenario, $this->carla))->toBe([$order->number]);
});

it('mostra nos decididos a decisão tomada por delegação, para o delegado e para o titular', function (): void {
    Fx::rule($this->scenario, $this->company, [[2, [$this->ana, $this->bruno]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Inbox::delegate($this->scenario, $this->ana, $this->davi);

    Fx::decide($this->scenario, $this->davi, $order, ApprovalDecision::Approved);

    $byDelegate = Inbox::overview($this->scenario, $this->davi, InboxTab::Decided)->entries->items()[0];
    $forPrincipal = Inbox::overview($this->scenario, $this->ana, InboxTab::Decided)->entries->items()[0];

    expect($byDelegate->decision?->principalName)->toBe(Inbox::name($this->ana))
        ->and($byDelegate->decision?->actorName)->toBeNull()
        ->and($forPrincipal->decision?->actorName)->toBe(Inbox::name($this->davi))
        ->and($forPrincipal->decision?->principalName)->toBeNull()
        ->and(Inbox::numbers($this->scenario, $this->ana))->toBe([])
        ->and(Inbox::numbers($this->scenario, $this->bruno))->toBe([$order->number]);

    $review = Inbox::review($this->scenario, $this->bruno, $order->id);
    expect($review->steps[0]->approvals)->toBe(1)
        ->and($review->steps[0]->approvers)->toEqualCanonicalizing([
            ['id' => $this->ana->id, 'name' => Inbox::name($this->ana), 'situation' => 'Aprovou (por '.Inbox::name($this->davi).', por delegação)', 'color' => 'green'],
            ['id' => $this->bruno->id, 'name' => Inbox::name($this->bruno), 'situation' => 'Aguardando decisão', 'color' => 'amber'],
        ])
        ->and($review->steps[0]->decisions[0]['actor'])->toBe(Inbox::name($this->davi))
        ->and($review->steps[0]->decisions[0]['principal'])->toBe(Inbox::name($this->ana));
});

it('pagina os pedidos aguardando do mais antigo para o mais recente, com o valor somado', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $first = Fx::place($this->scenario, $this->buyer);
    $second = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true));
    $third = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '2', sameCompany: true));
    Inbox::submittedAt($this->scenario, $third, '-3 days');
    Inbox::submittedAt($this->scenario, $first, '-5 hours');

    $page1 = Inbox::overview($this->scenario, $this->ana, InboxTab::Awaiting, 1, 2);
    $page2 = Inbox::overview($this->scenario, $this->ana, InboxTab::Awaiting, 2, 2);
    $now = CarbonImmutable::now();

    expect(array_map(static fn (InboxEntry $e): string => $e->order->number, $page1->entries->items()))->toBe([$third->number, $first->number])
        ->and(array_map(static fn (InboxEntry $e): string => $e->order->number, $page2->entries->items()))->toBe([$second->number])
        ->and($page1->entries->total())->toBe(3)
        ->and($page1->entries->lastPage())->toBe(2)
        ->and($page1->amount?->cents)->toBe(60_000)
        ->and($page1->entries->items()[0]->waiting($now))->toBe('3 dias')
        ->and($page1->entries->items()[1]->waiting($now))->toBe('5 horas')
        ->and($page2->entries->items()[0]->waiting($now))->toBe('menos de 1 hora')
        ->and($page1->entries->items()[0]->levelCount)->toBe(1)
        ->and($page1->entries->items()[0]->requesterName)->toBe(User::query()->findOrFail($third->placed_by_user_id)->name);
});

it('reúne no detalhe níveis, quórum, aprovadores, decisões e histórico', function (): void {
    $center = Fx::costCenter($this->scenario, $this->company);
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [2, [$this->bruno, $this->carla]]], name: 'Compras de obra');
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $center->id]);
    Fx::decide($this->scenario, $this->ana, $order, ApprovalDecision::Approved, 'Dentro do previsto');

    $review = Inbox::review($this->scenario, $this->bruno, $order->id);
    $stored = $this->scenario->system(fn (): Order => Order::query()->findOrFail($order->id));

    expect($review->costCenterName)->toBe('Centro OBRAS')
        ->and($review->requesterName)->toBe(Inbox::name($this->buyer['membership']))
        ->and($review->order->approval_rule_name)->toBe('Compras de obra')
        ->and($review->order->items)->toHaveCount(1)
        ->and($review->order->total_cents->cents)->toBe($stored->total_cents->cents)
        ->and($review->steps)->toHaveCount(2)
        ->and($review->steps[0]->step->status)->toBe(ApprovalStepStatus::Approved)
        ->and($review->steps[0]->current)->toBeFalse()
        ->and($review->steps[0]->decisions[0]['comment'])->toBe('Dentro do previsto')
        ->and($review->steps[0]->decisions[0]['principal'])->toBeNull()
        ->and($review->steps[1]->current)->toBeTrue()
        ->and($review->steps[1]->approvals)->toBe(0)
        ->and($review->steps[1]->blocked)->toBeFalse()
        ->and($review->current?->principalId)->toBe($this->bruno->id)
        ->and($review->canDecide())->toBeTrue()
        ->and($review->canReassign)->toBeFalse()
        ->and(array_column($review->history, 'label'))->toBe(['Enviado para aprovação interna', 'Nível aprovado'])
        ->and($review->history[0]['details'])->toContain('Regra: Compras de obra');
});
