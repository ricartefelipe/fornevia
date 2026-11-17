<?php

declare(strict_types=1);

use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Support\Navigation\Menu;
use Illuminate\Support\Facades\Route;
use Tests\Support\Builders\TenantScenario;

/*
| Menu: itens visíveis conforme o tipo de contexto (equipe ou comprador) e
| as permissões do papel ativo. Rotas ainda não registradas não aparecem.
|
| Algumas rotas de módulos futuros são registradas aqui para exercitar o
| filtro por permissão; as demais (preços, cotações, pedidos, estoque,
| expedição, devoluções e integrações) permanecem ausentes de propósito.
*/

beforeEach(function (): void {
    $this->withoutVite();

    foreach ([
        'buyers.index' => '/teste/menu/compradoras',
        'catalog.products' => '/teste/menu/catalogo',
        'credit.index' => '/teste/menu/credito',
        'finance.index' => '/teste/menu/financeiro',
        'audit.index' => '/teste/menu/auditoria',
        'subscription.show' => '/teste/menu/assinatura',
        'portal.catalog' => '/teste/menu/portal/catalogo',
        'portal.cart' => '/teste/menu/portal/carrinho',
        'portal.lists' => '/teste/menu/portal/listas',
        'portal.quotes' => '/teste/menu/portal/cotacoes',
        'portal.approvals' => '/teste/menu/portal/aprovacoes',
        'portal.orders' => '/teste/menu/portal/pedidos',
        'portal.budgets' => '/teste/menu/portal/orcamentos',
        'portal.users' => '/teste/menu/portal/usuarios',
    ] as $name => $uri) {
        Route::middleware('web')->get($uri, static fn (): string => $name)->name($name);
    }

    Route::getRoutes()->refreshNameLookups();

    $this->tenant = TenantScenario::create(name: 'Distribuidora Pedra Branca');
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço']);
    $this->menuFor = fn (Membership $membership): array => $this->tenant->actingAs(
        $membership,
        static fn (): array => array_column(app(Menu::class)->items(), 'route'),
    );
});

it('monta o menu da equipe conforme as permissões do papel', function (SystemRole $role, array $expected): void {
    $membership = $role === SystemRole::Owner
        ? $this->tenant->ownerMembership
        : $this->tenant->addStaff($role);

    expect(($this->menuFor)($membership))->toBe($expected);
})->with([
    'proprietário' => [SystemRole::Owner, [
        'dashboard', 'buyers.index', 'catalog.products', 'credit.index', 'finance.index',
        'audit.index', 'team.index', 'branches.index', 'subscription.show',
    ]],
    'administrador' => [SystemRole::Admin, [
        'dashboard', 'buyers.index', 'catalog.products', 'credit.index', 'finance.index',
        'audit.index', 'team.index', 'branches.index',
    ]],
    'representante comercial' => [SystemRole::SalesRep, ['dashboard', 'buyers.index', 'catalog.products', 'credit.index']],
    'estoque e expedição' => [SystemRole::Warehouse, ['dashboard', 'catalog.products']],
    'financeiro e crédito' => [SystemRole::Finance, ['dashboard', 'buyers.index', 'credit.index', 'finance.index', 'audit.index']],
    'consulta' => [SystemRole::Viewer, ['dashboard', 'buyers.index', 'catalog.products', 'credit.index', 'finance.index']],
]);

it('monta o menu do portal conforme as permissões do papel do comprador', function (SystemRole $role, array $expected): void {
    $membership = $this->tenant->addBuyerUser($this->company, $role);

    expect(($this->menuFor)($membership))->toBe($expected);
})->with([
    'administrador da empresa' => [SystemRole::BuyerAdmin, [
        'portal.home', 'portal.catalog', 'portal.cart', 'portal.lists', 'portal.quotes',
        'portal.approvals', 'portal.orders', 'portal.budgets', 'portal.users',
    ]],
    'comprador' => [SystemRole::Purchaser, ['portal.home', 'portal.catalog', 'portal.cart', 'portal.lists', 'portal.quotes', 'portal.orders']],
    'solicitante' => [SystemRole::Requester, ['portal.home', 'portal.catalog', 'portal.cart', 'portal.lists', 'portal.quotes', 'portal.orders']],
    'aprovador' => [SystemRole::Approver, ['portal.home', 'portal.catalog', 'portal.approvals', 'portal.orders']],
]);

it('reflete o ajuste de permissões feito pelo tenant em um papel', function (): void {
    $membership = $this->tenant->addStaff(SystemRole::Viewer);

    $this->tenant->system(function (): void {
        $role = Role::query()->where('key', SystemRole::Viewer->value)->firstOrFail();
        $role->forceFill([
            'permissions' => array_values(array_diff($role->permissions, [Permission::FinanceView->value, Permission::CreditView->value])),
        ])->save();
    });

    expect(($this->menuFor)($membership))->toBe(['dashboard', 'buyers.index', 'catalog.products']);
});

it('não exibe itens de navegação sem contexto ativo', function (): void {
    expect(app(Menu::class)->items())->toBe([]);
});

it('associa cada item ao rótulo de navegação correspondente', function (): void {
    $items = $this->tenant->actingAs($this->tenant->ownerMembership, static fn (): array => app(Menu::class)->items());

    expect($items)->toContain(['route' => 'dashboard', 'label' => 'Painel'])
        ->toContain(['route' => 'team.index', 'label' => 'Equipe e permissões'])
        ->toContain(['route' => 'branches.index', 'label' => 'Filiais'])
        ->toContain(['route' => 'subscription.show', 'label' => 'Assinatura']);
});

it('renderiza na navegação apenas os itens permitidos ao contexto da requisição', function (): void {
    $membership = $this->tenant->addStaff(SystemRole::SalesRep);

    $this->actingAs(User::query()->findOrFail($membership->user_id))
        ->withSession(TenantScenario::sessionFor($membership))
        ->get('/painel')
        ->assertOk()
        ->assertSee(route('buyers.index'))
        ->assertSee(route('catalog.products'))
        ->assertDontSee(route('team.index'))
        ->assertDontSee(route('branches.index'))
        ->assertDontSee(route('finance.index'))
        ->assertDontSee(route('portal.catalog'))
        ->assertDontSeeText('Equipe e permissões');
});
