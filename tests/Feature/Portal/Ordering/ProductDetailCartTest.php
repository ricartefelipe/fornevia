<?php

declare(strict_types=1);

use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\SystemRole;
use App\Livewire\Portal\Catalog\ProductDetail;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Botão "Adicionar ao carrinho" da página de produto do portal: usa a
| quantidade conferida pelo advisor (a mínima de compra quando nada foi
| digitado) e AddToCart confere de novo no servidor.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '100', unitPriceCents: 1_000);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->screw = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');
    $this->unpriced = Fixtures::sku($this->scenario, 'SEM-PRECO', priceCents: null, name: 'Bucha de nylon');

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
});

it('oferece o botão de carrinho a quem monta carrinho', function (): void {
    ($this->page)(route('portal.catalog.product', $this->screw->product_id))
        ->assertOk()
        ->assertSeeText('Adicionar ao carrinho');
});

it('adiciona a quantidade mínima quando nada foi digitado e a quantidade conferida depois', function (): void {
    $skuId = $this->screw->id;

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->screw->product_id])
        ->call('addToCart', $skuId)
        ->assertHasNoErrors()
        ->assertSee('12 UN de PAR-CX12 adicionado ao carrinho.')
        ->assertSee(route('portal.cart'))
        ->set("quantities.{$skuId}", '1.200')
        ->call('addToCart', $skuId)
        ->assertSee('1.200 UN de PAR-CX12 adicionado ao carrinho.'));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe(['PAR-CX12' => '1.212']);
});

it('não adiciona quantidade fora da regra de compra nem item sem preço', function (): void {
    $skuId = $this->screw->id;

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->screw->product_id])
        ->set("quantities.{$skuId}", '13')
        ->call('addToCart', $skuId)
        ->assertHasErrors(["cart.{$skuId}"])
        ->assertSee('Corrija a quantidade antes de adicionar ao carrinho.')
        ->assertDontSee('adicionado ao carrinho'));

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->unpriced->product_id])
        ->call('addToCart', $this->unpriced->id)
        ->assertHasErrors(["cart.{$this->unpriced->id}"])
        ->assertSee('está sem preço vigente para a sua empresa'));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([]);
});

it('trata como inexistente o SKU de outro produto ou adulterado', function (string $case): void {
    $skuId = match ($case) {
        'de outro produto' => $this->unpriced->id,
        'restrito' => Fixtures::sku($this->scenario, 'RES-9', options: ['visibility' => ProductVisibility::Restricted])->id,
        'malformado' => 'PAR-CX12',
    };

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->screw->product_id])
        ->call('addToCart', $skuId)
        ->assertNotFound());

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([]);
})->with(['de outro produto', 'restrito', 'malformado']);

it('oculta o botão e recusa a ação a quem vê o catálogo sem montar carrinho', function (): void {
    $approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver)['membership'];

    ($this->page)(route('portal.catalog.product', $this->screw->product_id), $approver)
        ->assertOk()
        ->assertDontSeeText('Adicionar ao carrinho');

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->screw->product_id])
        ->call('addToCart', $this->screw->id)
        ->assertForbidden(), $approver);

    expect(Fixtures::cartLines($this->scenario, $approver))->toBe([]);
});
