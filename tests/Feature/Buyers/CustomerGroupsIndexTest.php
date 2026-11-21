<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Buyers\CustomerGroupsIndex;
use App\Models\Buyers\CustomerGroup;
use App\Models\Identity\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->groups = fn (?TenantScenario $tenant = null): array => ($tenant ?? $this->tenant)->system(
        static fn (): array => CustomerGroup::query()->orderBy('code')->pluck('name', 'code')->all(),
    );
});

it('nega a página de grupos a quem não tem buyers.view e ao contexto de comprador', function (): void {
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::Warehouse);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.groups'))
        ->assertForbidden();

    $buyerUser = User::factory()->create()->fresh();
    $buyer = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin, $buyerUser);

    $this->actingAs($buyerUser)
        ->withSession(TenantScenario::sessionFor($buyer))
        ->get(route('buyers.groups'))
        ->assertForbidden();
});

it('exibe os grupos com a contagem de empresas e sem formulário para quem só consulta', function (): void {
    $group = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $this->tenant->addBuyerCompany(['customer_group_id' => $group->id]);
    $this->tenant->addBuyerCompany(['customer_group_id' => $group->id]);
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::Viewer);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.groups'))
        ->assertOk()
        ->assertSee('Construtoras')
        ->assertSeeInOrder(['CONSTRUTORAS', 'Construtoras', '2'])
        ->assertDontSee('Novo grupo');
});

it('cadastra, edita e exclui grupos pela tela', function (): void {
    Fixtures::livewireAs($this->tenant, $this->manager, function (): void {
        $component = Livewire::test(CustomerGroupsIndex::class)
            ->assertSee('Nenhum grupo de clientes cadastrado')
            ->set('code', 'revendas')
            ->set('name', 'Revendas')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Grupo cadastrado.')
            ->assertSee('REVENDAS');

        $group = CustomerGroup::query()->where('code', 'REVENDAS')->sole();

        $component->call('edit', $group->id)
            ->assertSet('editingId', $group->id)
            ->set('name', 'Revendas do interior')
            ->call('save')
            ->assertSet('message', 'Grupo atualizado.')
            ->assertSet('editingId', null)
            ->assertSee('Revendas do interior')
            ->call('delete', $group->id)
            ->assertSet('message', 'Grupo REVENDAS excluído.');
    });

    expect(($this->groups)())->toBe([])
        ->and(Fixtures::audits($this->tenant, 'customer_group.deleted'))->toHaveCount(1);
});

it('indica código repetido no campo e recusa excluir grupo em uso', function (): void {
    $group = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $this->tenant->addBuyerCompany(['customer_group_id' => $group->id]);

    Fixtures::livewireAs($this->tenant, $this->manager, static fn () => Livewire::test(CustomerGroupsIndex::class)
        ->set('code', 'construtoras')
        ->set('name', 'Construtoras 2')
        ->call('save')
        ->assertHasErrors(['code' => 'Já existe um grupo de clientes com este código.'])
        ->call('delete', $group->id)
        ->assertSet('failure', 'O grupo possui empresas, tabelas de preço ou catálogos privados vinculados e não pode ser excluído.')
        ->assertSee('não pode ser excluído'));

    expect(($this->groups)())->toBe(['CONSTRUTORAS' => 'Construtoras']);
});

it('trata grupo de outro distribuidor como inexistente e protege editingId', function (): void {
    $foreign = Fixtures::group(TenantScenario::create(), 'REVENDAS', 'Revendas');
    $own = Fixtures::group($this->tenant, 'ATACADO', 'Atacado');

    Fixtures::livewireAs($this->tenant, $this->manager, static function () use ($foreign, $own): void {
        Livewire::test(CustomerGroupsIndex::class)->call('edit', $foreign->id)->assertNotFound();
        Livewire::test(CustomerGroupsIndex::class)->call('delete', $foreign->id)->assertNotFound();
        Livewire::test(CustomerGroupsIndex::class)->call('edit', 'ATACADO')->assertNotFound();

        expect(fn () => Livewire::test(CustomerGroupsIndex::class)->call('edit', $own->id)->set('editingId', $foreign->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });

    expect(($this->groups)())->toBe(['ATACADO' => 'Atacado']);
});

it('refaz a autorização ao salvar e excluir grupos', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $group = Fixtures::group($this->tenant, 'ATACADO', 'Atacado');

    Fixtures::livewireAs($this->tenant, $seller, static function () use ($group): void {
        Livewire::test(CustomerGroupsIndex::class)
            ->set('code', 'REVENDAS')
            ->set('name', 'Revendas')
            ->call('save')
            ->assertForbidden();

        Livewire::test(CustomerGroupsIndex::class)->call('delete', $group->id)->assertForbidden();
    });

    expect(($this->groups)())->toBe(['ATACADO' => 'Atacado']);
});
