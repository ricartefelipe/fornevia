<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Inventory\WarehousesIndex;
use App\Models\Identity\Branch;
use App\Models\Inventory\Warehouse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->stockist = $this->tenant->addStaff(SystemRole::Warehouse);

    $this->stored = fn (?TenantScenario $tenant = null): array => ($tenant ?? $this->tenant)->system(static fn (): array => Warehouse::query()
        ->orderBy('code')
        ->get()
        ->map(static fn (Warehouse $w): array => [$w->code, $w->name, $w->priority, $w->allows_sales, $w->is_active])
        ->all());
});

it('cadastra e edita depósito pela tela, com a matriz como filial padrão', function (): void {
    $headquarters = $this->tenant->system(static fn (): string => (string) Branch::query()->where('is_headquarters', true)->value('id'));

    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(WarehousesIndex::class)
        ->assertSet('branchId', $headquarters)
        ->set('code', 'cd1')
        ->set('name', 'Centro de distribuição Campinas')
        ->set('priority', '20')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Depósito CD1 cadastrado.')
        ->assertSet('code', '')
        ->assertSee('Centro de distribuição Campinas'));

    $warehouse = $this->tenant->system(static fn (): Warehouse => Warehouse::query()->where('code', 'CD1')->sole());

    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(WarehousesIndex::class)
        ->call('edit', $warehouse->id)
        ->assertSet('editingId', $warehouse->id)
        ->assertSet('priority', '20')
        ->set('allowsSales', false)
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Depósito CD1 atualizado.')
        ->assertSet('editingId', null));

    expect(($this->stored)())->toBe([['CD1', 'Centro de distribuição Campinas', 20, false, false]]);
});

it('mostra erros de validação e de código repetido nos campos', function (): void {
    Fx::warehouse($this->tenant, 'CD1');

    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(WarehousesIndex::class)
        ->set('code', 'cd1')
        ->set('name', 'Outro centro')
        ->call('save')
        ->assertHasErrors(['code' => 'Já existe um depósito com o código CD1.'])
        ->set('code', 'CD 2')
        ->call('save')
        ->assertHasErrors(['code'])
        ->set('code', 'CD2')
        ->set('priority', '-1')
        ->call('save')
        ->assertHasErrors(['priority'])
        ->set('priority', '1')
        ->set('branchId', 'matriz')
        ->call('save')
        ->assertHasErrors(['branchId']));

    expect(($this->stored)())->toHaveCount(1);
});

it('exclui depósito sem histórico e recusa excluir depósito com movimentações', function (): void {
    $empty = Fx::warehouse($this->tenant, 'ERRADO');
    $used = Fx::warehouse($this->tenant, 'CD1');
    Fx::receive($this->tenant, $used, Fx::sku($this->tenant), '5');

    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(WarehousesIndex::class)
        ->assertSeeHtml("delete('{$empty->id}')")
        ->assertDontSeeHtml("delete('{$used->id}')")
        ->call('delete', $empty->id)
        ->assertHasNoErrors()
        ->assertSet('message', 'Depósito ERRADO excluído.')
        ->call('delete', $used->id)
        ->assertHasErrors(['delete'])
        ->assertSee('O depósito CD1 possui histórico de estoque e não pode ser excluído.'));

    expect(array_column(($this->stored)(), 0))->toBe(['CD1']);
});

it('protege o depósito em edição e trata ids de outro tenant ou malformados como inexistentes', function (): void {
    $own = Fx::warehouse($this->tenant, 'CD1');
    $other = TenantScenario::create();
    $foreign = Fx::warehouse($other, 'CD9');

    Fx::asMember($this->tenant, $this->stockist, static function () use ($own, $foreign): void {
        $component = Livewire::test(WarehousesIndex::class)->call('edit', $own->id);

        expect(fn () => $component->set('editingId', $foreign->id))->toThrow(CannotUpdateLockedPropertyException::class);

        Livewire::test(WarehousesIndex::class)->call('edit', $foreign->id)->assertNotFound();
        Livewire::test(WarehousesIndex::class)->call('delete', $foreign->id)->assertNotFound();
        Livewire::test(WarehousesIndex::class)->call('edit', 'CD1')->assertNotFound();
    });

    expect(($this->stored)($other))->toBe([['CD9', 'Depósito CD9', 10, true, true]]);
});

it('esconde o formulário de quem só consulta e recusa a gravação no servidor', function (): void {
    $warehouse = Fx::warehouse($this->tenant, 'CD1');

    Fx::asMember($this->tenant, $this->tenant->addStaff(SystemRole::Viewer), static fn () => Livewire::test(WarehousesIndex::class)
        ->assertSee('CD1')
        ->assertDontSee('Novo depósito')
        ->assertDontSeeHtml("edit('{$warehouse->id}')")
        ->set('code', 'INDEVIDO')
        ->set('name', 'Depósito indevido')
        ->call('save')
        ->assertForbidden());

    Fx::asMember($this->tenant, $this->tenant->addStaff(SystemRole::Viewer), static fn () => Livewire::test(WarehousesIndex::class)
        ->call('delete', $warehouse->id)
        ->assertForbidden());

    expect(array_column(($this->stored)(), 0))->toBe(['CD1']);
});
