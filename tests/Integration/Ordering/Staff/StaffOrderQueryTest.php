<?php

declare(strict_types=1);

use App\Actions\Ordering\Staff\StaffOrderQuery;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Ordering\Order;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Escopo dos pedidos da equipe: orders.view, isolamento entre distribuidores
| e carteira do representante comercial (empresas pelas quais responde hoje).
| A RLS isola tenants e compradores, mas trata toda a equipe como capaz de
| ver todas as empresas; a carteira é aplicada pela consulta.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50');
    $this->tenant = $this->scenario->tenant;

    $this->visible = fn (Membership $member, ?TenantScenario $tenant = null): array => ($tenant ?? $this->tenant)->actingAs(
        $member,
        static fn (): array => app(StaffOrderQuery::class)->orders()->orderBy('number')->pluck('number')->all(),
    );
    $this->find = fn (Membership $member, mixed $id): Order => $this->tenant->actingAs(
        $member,
        static fn (): Order => app(StaffOrderQuery::class)->find($id),
    );
});

it('lista apenas os pedidos do próprio distribuidor', function (): void {
    $own = Fx::place($this->scenario)['order'];
    $otherScenario = OrderingScenario::create();
    $foreign = Fx::place($otherScenario)['order'];
    $manager = $this->tenant->addStaff(SystemRole::SalesManager);

    expect(($this->visible)($manager))->toBe([$own->number])
        ->and(fn () => ($this->find)($manager, $foreign->id))->toThrow(ModelNotFoundException::class)
        ->and(($this->visible)($otherScenario->tenant->addStaff(SystemRole::SalesManager), $otherScenario->tenant))->toBe([$foreign->number]);
});

it('trata identificadores malformados como pedido inexistente', function (mixed $id): void {
    Fx::place($this->scenario);
    $manager = $this->tenant->addStaff(SystemRole::SalesManager);

    expect(fn () => ($this->find)($manager, $id))->toThrow(ModelNotFoundException::class);
})->with([
    'número do pedido' => ['PED-0000001'],
    'lista' => [['0195f5d4-0000-7000-8000-000000000000']],
    'vazio' => [''],
    'nulo' => [null],
]);

it('aceita o identificador em maiúsculas na forma canônica', function (): void {
    $order = Fx::place($this->scenario)['order'];
    $manager = $this->tenant->addStaff(SystemRole::SalesManager);

    expect(($this->find)($manager, strtoupper($order->id))->id)->toBe($order->id);
});

it('restringe o representante comercial aos pedidos das empresas da sua carteira', function (): void {
    $rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $colleague = $this->tenant->addStaff(SystemRole::SalesRep);
    $mine = Fx::place($this->scenario);
    $theirs = Fx::place($this->scenario);
    $unassigned = Fx::place($this->scenario);
    Fx::assignRep($this->tenant, $mine['company'], $rep);
    Fx::assignRep($this->tenant, $theirs['company'], $colleague);

    expect(($this->visible)($rep))->toBe([$mine['order']->number])
        ->and(($this->visible)($colleague))->toBe([$theirs['order']->number])
        ->and(($this->find)($rep, $mine['order']->id)->id)->toBe($mine['order']->id)
        ->and(fn () => ($this->find)($rep, $theirs['order']->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->find)($rep, $unassigned['order']->id))->toThrow(ModelNotFoundException::class)
        ->and($this->tenant->actingAs($rep, static fn (): array => app(StaffOrderQuery::class)->companies()->pluck('id')->all()))
        ->toBe([$mine['company']->id]);
});

it('acompanha a transferência da empresa entre carteiras', function (): void {
    $rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $successor = $this->tenant->addStaff(SystemRole::SalesRep);
    $placed = Fx::place($this->scenario, prepare: fn (array $buyer) => Fx::assignRep($this->tenant, $buyer['company'], $rep));

    // O pedido guarda o representante da confirmação apenas como histórico.
    $recorded = $this->tenant->system(static fn () => DB::table('orders')->where('id', $placed['order']->id)->value('sales_rep_membership_id'));

    Fx::assignRep($this->tenant, $placed['company'], $successor);

    expect($recorded)->toBe($rep->id)
        ->and(($this->visible)($rep))->toBe([])
        ->and(($this->visible)($successor))->toBe([$placed['order']->number]);
});

it('não aplica o recorte de carteira aos demais papéis da equipe', function (SystemRole $role): void {
    $rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $assigned = Fx::place($this->scenario);
    $other = Fx::place($this->scenario);
    Fx::assignRep($this->tenant, $assigned['company'], $rep);

    $member = $role === SystemRole::Owner ? $this->tenant->ownerMembership : $this->tenant->addStaff($role);
    $expected = collect([$assigned['order']->number, $other['order']->number])->sort()->values()->all();

    expect(($this->visible)($member))->toBe($expected);
})->with([
    'proprietário' => [SystemRole::Owner],
    'gerente comercial' => [SystemRole::SalesManager],
    'estoque e expedição' => [SystemRole::Warehouse],
    'financeiro e crédito' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('exige orders.view e contexto de equipe', function (): void {
    $placed = Fx::place($this->scenario);
    $viewer = $this->tenant->addStaff(SystemRole::Viewer);
    Fx::adjustRole($this->tenant, SystemRole::Viewer, revoke: [Permission::OrdersView]);

    expect(fn () => ($this->visible)($viewer))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->visible)($placed['membership']))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->find)($placed['membership'], $placed['order']->id))->toThrow(AuthorizationException::class);
});
