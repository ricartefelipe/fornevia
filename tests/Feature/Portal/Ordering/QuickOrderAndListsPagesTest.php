<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Portal\Ordering\QuickOrderPage;
use App\Livewire\Portal\Ordering\ShoppingListShow;
use App\Livewire\Portal\Ordering\ShoppingListsIndex;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\ShoppingList;
use App\Models\Ordering\ShoppingListItem;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas do pedido rápido (portal.quick-order) e das listas de compra
| (portal.lists, portal.lists.show): relatório por linha, criação,
| edição pelo dono, leitura de listas compartilhadas e envio ao carrinho.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '100', unitPriceCents: 1_000);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->colleague = Fixtures::buyer($this->scenario, $this->buyer['company']);
    $this->screw = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->buyer['membership'];
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get($url);
    };

    $this->asBuyer = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->buyer['membership'];
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };

    $this->list = function (string $name, bool $shared = false, ?Membership $owner = null, array $items = []): ShoppingList {
        $owner ??= $this->buyer['membership'];

        return $this->scenario->system(static function () use ($name, $shared, $owner, $items): ShoppingList {
            $list = new ShoppingList;
            $list->forceFill(['buyer_company_id' => $owner->buyer_company_id, 'membership_id' => $owner->id, 'name' => $name, 'is_shared' => $shared])->save();

            foreach ($items as $skuId => $quantity) {
                (new ShoppingListItem)->forceFill(['shopping_list_id' => $list->id, 'buyer_company_id' => $list->buyer_company_id, 'sku_id' => $skuId, 'quantity' => $quantity])->save();
            }

            return $list;
        });
    };
});

it('exibe o pedido rápido com as orientações de formato', function (): void {
    ($this->page)(route('portal.quick-order'))
        ->assertOk()
        ->assertSeeText('Pedido rápido')
        ->assertSeeText('Itens (um por linha)')
        ->assertSeeText('Até 200 linhas por vez.');
});

it('adiciona as linhas válidas, mostra o resultado por linha e mantém as recusadas no campo', function (): void {
    $code = $this->scenario->sku->code;

    ($this->asBuyer)(fn () => Livewire::test(QuickOrderPage::class)
        ->set('text', "{$code} 2\nPAR-CX12;13\nXYZ-1 4")
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('1 item adicionado ao carrinho; 2 não adicionados.')
        ->assertSee('Este item é vendido em múltiplos de 12 UN.')
        ->assertSee('Quantidade válida mais próxima:')
        ->assertSee('Código XYZ-1 não encontrado entre os produtos disponíveis para a sua empresa.')
        ->assertSee(route('portal.cart'))
        ->assertSet('text', "PAR-CX12;13\nXYZ-1 4"));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([$code => '2']);
});

it('mostra no campo o erro de texto vazio ou longo demais', function (string $text, string $message): void {
    ($this->asBuyer)(fn () => Livewire::test(QuickOrderPage::class)
        ->set('text', $text)
        ->call('submit')
        ->assertHasErrors(['text'])
        ->assertSee($message)
        ->assertSet('report', null));
})->with([
    'vazio' => ['', 'Cole ao menos uma linha no formato'],
    'longo' => [str_repeat('PAR-CX12 12 ', 2_000), 'O texto colado excede o limite de 20.000 caracteres.'],
]);

it('protege o relatório do pedido rápido contra alteração pelo cliente', function (): void {
    ($this->asBuyer)(function (): void {
        $component = Livewire::test(QuickOrderPage::class);

        expect(fn () => $component->set('report', ['summary' => 'falso', 'added' => 1, 'rejected' => 0, 'lines' => []]))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('cria a lista pela tela e leva à página dela', function (): void {
    $component = ($this->asBuyer)(fn () => Livewire::test(ShoppingListsIndex::class)
        ->assertSee('Nenhuma lista de compra')
        ->set('name', '')
        ->call('create')
        ->assertHasErrors(['name'])
        ->assertSee('Informe o nome da lista.')
        ->set('name', 'Reposição semanal')
        ->set('shared', true)
        ->call('create')
        ->assertHasNoErrors());

    $list = $this->scenario->system(static fn (): ShoppingList => ShoppingList::query()->sole());
    $component->assertRedirect(route('portal.lists.show', $list->id));

    expect($list->name)->toBe('Reposição semanal')->and($list->is_shared)->toBeTrue();
});

it('lista as próprias e as compartilhadas, sem as pessoais de colegas, e envia uma lista ao carrinho', function (): void {
    $own = ($this->list)('Minha lista', items: [$this->screw->id => '24']);
    ($this->list)('Lista da obra', true, $this->colleague['membership'], [$this->scenario->sku->id => '2']);
    ($this->list)('Rascunho do colega', false, $this->colleague['membership']);

    ($this->page)(route('portal.lists'))
        ->assertOk()
        ->assertSeeText('Minha lista')
        ->assertSeeText('Lista da obra')
        ->assertSeeText('Compartilhada')
        ->assertDontSeeText('Rascunho do colega');

    ($this->asBuyer)(fn () => Livewire::test(ShoppingListsIndex::class)
        ->call('addToCart', $own->id)
        ->assertSee('Resultado da lista Minha lista')
        ->assertSee('1 item adicionado ao carrinho.')
        ->call('delete', $own->id)
        ->assertSee('Lista Minha lista excluída.'));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe(['PAR-CX12' => '24']);
});

it('recusa pela tela a exclusão de lista compartilhada de colega', function (): void {
    $shared = ($this->list)('Lista da obra', true, $this->colleague['membership']);

    ($this->asBuyer)(fn () => Livewire::test(ShoppingListsIndex::class)
        ->call('delete', $shared->id)
        ->assertSee('Somente quem criou a lista pode alterá-la.'));

    expect($this->scenario->system(static fn (): int => ShoppingList::query()->count()))->toBe(1);
});

it('edita a lista pela página dela: inclui, altera, remove itens e renomeia', function (): void {
    $list = ($this->list)('Obra sul');

    ($this->asBuyer)(function () use ($list): void {
        $component = Livewire::test(ShoppingListShow::class, ['list' => $list->id])
            ->assertSee('Lista sem itens')
            ->set('skuCode', 'par-cx12')
            ->set('quantity', '13')
            ->call('addItem')
            ->assertHasErrors(['quantity'])
            ->assertSee('Quantidade válida mais próxima: 24 UN.')
            ->set('skuCode', 'NAO-EXISTE')
            ->set('quantity', '1')
            ->call('addItem')
            ->assertHasErrors(['skuCode'])
            ->set('skuCode', 'par-cx12')
            ->set('quantity', '24')
            ->call('addItem')
            ->assertHasNoErrors()
            ->assertSee('PAR-CX12 incluído na lista.')
            ->assertSet('skuCode', '');

        $itemId = (string) array_key_first($component->get('quantities'));

        $component->set("quantities.{$itemId}", '48')
            ->call('updateItem', $itemId)
            ->assertSee('Quantidade de PAR-CX12 atualizada.')
            ->set('name', 'Obra sul - fase 2')
            ->call('rename')
            ->assertSee('Lista atualizada.')
            ->call('removeItem', $itemId)
            ->assertSee('Item removido da lista.')
            ->assertSee('Lista sem itens');
    });

    expect($this->scenario->system(fn (): string => ShoppingList::query()->findOrFail($list->id)->name))->toBe('Obra sul - fase 2');
});

it('mostra a lista compartilhada de colega sem edição e permite enviá-la ao carrinho', function (): void {
    $shared = ($this->list)('Lista da obra', true, $this->colleague['membership'], [$this->screw->id => '12']);

    ($this->page)(route('portal.lists.show', $shared->id))
        ->assertOk()
        ->assertSeeText('Lista compartilhada por')
        ->assertSeeText('Adicionar lista ao carrinho')
        ->assertDontSeeText('Incluir item')
        ->assertDontSeeText('Excluir lista');

    $itemId = $this->scenario->system(static fn () => ShoppingListItem::query()->value('id'));

    ($this->asBuyer)(fn () => Livewire::test(ShoppingListShow::class, ['list' => $shared->id])
        ->call('addToCart')
        ->assertSee('1 item adicionado ao carrinho.')
        ->call('removeItem', $itemId)
        ->assertSee('Somente quem criou a lista pode alterá-la.'));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe(['PAR-CX12' => '12']);
});

it('responde 404 à lista pessoal de colega, de outra empresa, de outro tenant ou malformada', function (string $case): void {
    $id = match ($case) {
        'pessoal de colega' => ($this->list)('Rascunho', false, $this->colleague['membership'])->id,
        'outra empresa' => ($this->list)('Externa', true, Fixtures::buyer($this->scenario)['membership'])->id,
        'outro tenant' => (string) Str::uuid7(),
        'malformada' => 'lista-1',
    };

    ($this->page)(route('portal.lists.show', $id))->assertNotFound();
})->with(['pessoal de colega', 'outra empresa', 'outro tenant', 'malformada']);

it('trata como inexistente o item de lista adulterado', function (): void {
    $list = ($this->list)('Obra sul', items: [$this->screw->id => '12']);
    $foreign = ($this->list)('Outra', false, $this->buyer['membership'], [$this->scenario->sku->id => '1']);
    $foreignItem = $this->scenario->system(static fn () => ShoppingListItem::query()->where('shopping_list_id', $foreign->id)->value('id'));

    ($this->asBuyer)(fn () => Livewire::test(ShoppingListShow::class, ['list' => $list->id])->call('removeItem', $foreignItem)->assertNotFound());
    ($this->asBuyer)(fn () => Livewire::test(ShoppingListShow::class, ['list' => $list->id])->call('updateItem', 'item-1')->assertNotFound());

    expect(fn () => ($this->asBuyer)(fn () => Livewire::test(ShoppingListShow::class, ['list' => $list->id])->set('listId', $foreign->id)))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('nega as listas ao aprovador sem permissão de carrinho', function (): void {
    $approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver)['membership'];
    $shared = ($this->list)('Lista da obra', true);

    ($this->page)(route('portal.lists.show', $shared->id), $approver)->assertForbidden();
});
