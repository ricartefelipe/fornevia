<?php

declare(strict_types=1);

use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\CartStatus;
use App\Livewire\Portal\Ordering\CartPage;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use App\Models\Ordering\Order;
use App\Models\Pricing\PaymentTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Tela do carrinho e checkout (portal.cart): linhas precificadas no
| servidor, alteração e remoção, referências de checkout da própria empresa,
| confirmação via PlaceOrder, idempotência por tentativa de envio e
| proteção das propriedades bloqueadas.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '40', unitPriceCents: 2_590);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->screw = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');
    $this->cart = Fixtures::fillCart($this->scenario, $this->buyer['membership'], [$this->scenario->sku->id => '3', $this->screw->id => '24']);
    $this->items = $this->scenario->system(fn (): array => CartItem::query()->where('cart_id', $this->cart->id)->pluck('id', 'sku_id')->all());

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

    $this->orders = fn (): int => $this->scenario->system(static fn (): int => Order::query()->count());
});

it('exibe itens, preços do servidor, disponibilidade, totais e opções de checkout', function (): void {
    ($this->page)(route('portal.cart'))
        ->assertOk()
        ->assertSeeText('Carrinho')
        ->assertSeeText('Disjuntor bipolar 32A')
        ->assertSeeText($this->scenario->sku->code)
        ->assertSeeText('Parafuso sextavado PAR-CX12')
        ->assertSeeText('R$ 25,90')
        ->assertSeeText('R$ 77,70')
        ->assertSeeText('R$ 72,00')
        ->assertSeeText('R$ 149,70')
        ->assertSeeText('Disponível')
        ->assertSeeText('Depósito central: Rua das Indústrias, 200')
        ->assertSeeText('À vista')
        ->assertSeeText('Referência da sua ordem de compra (opcional)')
        ->assertSee(route('portal.quick-order'), escape: false);
});

it('mostra o carrinho vazio sem criar carrinho', function (): void {
    $other = Fixtures::buyer($this->scenario);

    ($this->page)(route('portal.cart'), $other['membership'])
        ->assertOk()
        ->assertSeeText('Seu carrinho está vazio');

    expect($this->scenario->system(static fn (): int => Cart::query()->where('membership_id', $other['membership']->id)->count()))->toBe(0);
});

it('nega as telas de carrinho, pedido rápido e listas sem a permissão ou fora do contexto de comprador', function (string $route): void {
    $approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver)['membership'];
    $staff = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    ($this->page)(route($route), $approver)->assertForbidden();
    ($this->page)(route($route), $staff)->assertForbidden();
})->with(['portal.cart', 'portal.quick-order', 'portal.lists']);

it('altera a quantidade, mostra o erro de regra no campo e remove itens', function (): void {
    $screwItem = $this->items[$this->screw->id];

    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->assertSet("quantities.{$screwItem}", '24')
        ->set("quantities.{$screwItem}", '36')
        ->call('updateQuantity', $screwItem)
        ->assertHasNoErrors()
        ->assertSee('Quantidade atualizada.')
        ->assertSee('R$ 108,00')
        ->set("quantities.{$screwItem}", '13')
        ->call('updateQuantity', $screwItem)
        ->assertHasErrors(["quantities.{$screwItem}"])
        ->assertSee('Este item é vendido em múltiplos de 12 UN. Quantidade válida mais próxima: 24 UN.')
        ->call('removeItem', $screwItem)
        ->assertSee('Item removido do carrinho.')
        ->assertDontSee('PAR-CX12'));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([$this->scenario->sku->code => '3']);
});

it('trata como inexistente a linha adulterada ou de outro carrinho', function (string $case): void {
    $itemId = match ($case) {
        'malformado' => 'linha-1',
        'inexistente' => (string) Str::uuid7(),
        'de colega' => (function (): string {
            $cart = Fixtures::fillCart($this->scenario, Fixtures::buyer($this->scenario, $this->buyer['company'])['membership'], [$this->screw->id => '12']);

            return $this->scenario->system(static fn (): string => CartItem::query()->where('cart_id', $cart->id)->value('id'));
        })(),
    };

    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)->call('removeItem', $itemId)->assertNotFound());
    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)->call('updateQuantity', $itemId)->assertNotFound());

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toHaveCount(2);
})->with(['malformado', 'inexistente', 'de colega']);

it('confirma o pedido com referência da ordem de compra e leva à tela de confirmação', function (): void {
    $component = ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->assertSet('shippingAddressId', $this->buyer['address']->id)
        ->assertSet('paymentTermId', $this->scenario->paymentTerm->id)
        ->set('purchaseOrderReference', 'OC-2026-0458')
        ->set('notes', 'Entregar no portão 3')
        ->call('placeOrder')
        ->assertHasNoErrors());

    $order = $this->scenario->system(static fn (): Order => Order::query()->with('items')->sole());
    $component->assertRedirect(route('portal.orders.confirmation', $order->id));

    expect($order->purchase_order_reference)->toBe('OC-2026-0458')
        ->and($order->notes)->toBe('Entregar no portão 3')
        ->and($order->total_cents->cents)->toBe(14_970)
        ->and($order->items)->toHaveCount(2)
        ->and($this->scenario->system(fn () => Cart::query()->findOrFail($this->cart->id)->status))->toBe(CartStatus::Converted);

    ($this->page)(route('portal.orders.confirmation', $order->id))
        ->assertOk()
        ->assertSeeText("Pedido {$order->number} confirmado")
        ->assertSeeText('OC-2026-0458')
        ->assertSeeText('R$ 149,70')
        ->assertSeeText('Reservado');
});

it('recusa a confirmação quando o total mudou desde a exibição e usa nova chave na próxima tentativa', function (): void {
    $component = ($this->asBuyer)(fn () => Livewire::test(CartPage::class)->assertSee('R$ 149,70'));
    $firstKey = $component->get('checkoutKey');

    Fixtures::setPrice($this->scenario, $this->scenario->sku, 2_990);

    ($this->asBuyer)(fn () => $component->call('placeOrder')
        ->assertHasErrors(['checkout'])
        ->assertSee('Os valores mudaram desde a última exibição.')
        ->assertSee('R$ 161,70'));

    expect($component->get('checkoutKey'))->not->toBe($firstKey)
        ->and(($this->orders)())->toBe(0);

    ($this->asBuyer)(fn () => $component->call('placeOrder')->assertHasNoErrors());

    expect($this->scenario->system(static fn (): int => Order::query()->sole()->total_cents->cents))->toBe(16_170);
});

it('valida no campo as referências de checkout que não são da empresa', function (string $field, Closure $value): void {
    $resolved = $value->call($this);

    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->set($field, $resolved)
        ->call('placeOrder')
        ->assertHasErrors([$field]));

    expect(($this->orders)())->toBe(0);
})->with([
    'endereço de outra empresa' => ['shippingAddressId', fn (): string => Fixtures::buyer($this->scenario)['address']->id],
    'endereço malformado' => ['shippingAddressId', fn (): string => 'endereco-1'],
    'endereço vazio' => ['shippingAddressId', fn (): string => ''],
    'condição não permitida' => ['paymentTermId', fn (): string => $this->scenario->system(static fn () => PaymentTerm::query()->where('code', '30-60')->value('id'))],
    'cobrança inexistente' => ['billingAddressId', fn (): string => (string) Str::uuid7()],
    'centro de custo inexistente' => ['costCenterId', fn (): string => (string) Str::uuid7()],
    'ordem de compra longa' => ['purchaseOrderReference', fn (): string => str_repeat('9', 61)],
    'observação longa' => ['notes', fn (): string => str_repeat('a', 1_001)],
]);

it('filtra centros de custo e endereços pela unidade e grava unidade, centro de custo e cobrança no pedido', function (): void {
    $companyId = $this->buyer['company']->id;
    [$north, $northCenter, $southCenter, $billing] = $this->scenario->system(static function () use ($companyId): array {
        $make = static function (string $class, array $attributes) use ($companyId): Illuminate\Database\Eloquent\Model {
            $model = new $class;
            $model->forceFill(['buyer_company_id' => $companyId, ...$attributes])->save();

            return $model;
        };
        $north = $make(App\Models\Buyers\BuyerUnit::class, ['code' => 'NORTE', 'name' => 'Obra Norte']);
        $south = $make(App\Models\Buyers\BuyerUnit::class, ['code' => 'SUL', 'name' => 'Obra Sul']);

        return [
            $north,
            $make(App\Models\Buyers\CostCenter::class, ['code' => 'CC-NORTE', 'name' => 'Fundação norte', 'buyer_unit_id' => $north->id]),
            $make(App\Models\Buyers\CostCenter::class, ['code' => 'CC-SUL', 'name' => 'Fundação sul', 'buyer_unit_id' => $south->id]),
            $make(App\Models\Buyers\BuyerAddress::class, [
                'kind' => 'billing', 'label' => 'Financeiro', 'street' => 'Avenida Paulista', 'number' => '1000', 'district' => 'Bela Vista',
                'city' => 'São Paulo', 'state' => 'SP', 'postal_code' => '01310100', 'is_default' => true, 'is_active' => true,
            ]),
        ];
    });

    $component = ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->assertSet('billingAddressId', $billing->id)
        ->assertSee('CC-NORTE · Fundação norte (Obra Norte)')
        ->assertSee('CC-SUL · Fundação sul (Obra Sul)')
        ->set('unitId', $north->id)
        ->assertSee('CC-NORTE · Fundação norte')
        ->assertDontSee('CC-SUL · Fundação sul')
        ->set('costCenterId', $southCenter->id)
        ->call('placeOrder')
        ->assertHasErrors(['costCenterId'])
        ->set('costCenterId', $northCenter->id)
        ->call('placeOrder')
        ->assertHasNoErrors());

    $order = $this->scenario->system(static fn (): Order => Order::query()->sole());
    $component->assertRedirect(route('portal.orders.confirmation', $order->id));

    expect($order->buyer_unit_id)->toBe($north->id)
        ->and($order->cost_center_id)->toBe($northCenter->id)
        ->and($order->billing_address['label'] ?? null)->toBe('Financeiro');

    ($this->page)(route('portal.orders.show', $order->id))
        ->assertOk()
        ->assertSeeText('Obra Norte')
        ->assertSeeText('Fundação norte')
        ->assertSeeText('Avenida Paulista, 1000');
});

it('descarta unidade que não é da empresa', function (): void {
    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->set('unitId', (string) Str::uuid7())
        ->assertSet('unitId', ''));
});

it('protege carrinho, total exibido e chave de envio contra alteração pelo cliente', function (string $property): void {
    ($this->asBuyer)(function () use ($property): void {
        $component = Livewire::test(CartPage::class);

        expect(fn () => $component->set($property, $property === 'displayedTotalCents' ? 1 : (string) Str::uuid7()))
            ->toThrow(CannotUpdateLockedPropertyException::class);
    });
})->with(['checkoutKey', 'displayedCartId', 'displayedTotalCents', 'message']);

it('devolve o mesmo pedido ao reenviar a mesma tentativa pelo endpoint real', function (): void {
    $page = ($this->page)(route('portal.cart'))->assertOk();
    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $match);
    $snapshot = html_entity_decode($match[1] ?? '', ENT_QUOTES | ENT_HTML5);

    $send = function (string $reference = 'OC-77') use ($snapshot): TestResponse {
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($this->buyer['membership']->user_id))
            ->withSession(TenantScenario::sessionFor($this->buyer['membership']))
            ->json('POST', app(HandleRequests::class)->getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => ['purchaseOrderReference' => $reference],
                    'calls' => [['method' => 'placeOrder', 'params' => [], 'metadata' => []]],
                ]],
            ], ['X-Livewire' => '1', 'Accept' => '*/*']);
    };

    $first = (string) $send()->assertOk()->json('components.0.effects.redirect');
    $second = (string) $send()->assertOk()->json('components.0.effects.redirect');
    $order = $this->scenario->system(static fn (): Order => Order::query()->sole());

    // A mesma tentativa com outro conteúdo não gera outro pedido.
    $changed = $send('OC-78')->assertOk();

    expect($first)->toBe(route('portal.orders.confirmation', $order->id))
        ->and($second)->toBe($first)
        ->and($changed->json('components.0.effects.redirect'))->toBeNull()
        ->and((string) $changed->json('components.0.effects.html'))->toContain('Este envio já foi processado com outros dados.')
        ->and($this->scenario->system(static fn (): int => Order::query()->count()))->toBe(1)
        ->and($order->purchase_order_reference)->toBe('OC-77')
        ->and($this->scenario->system(static fn (): int => DB::table('stock_reservations')->count()))->toBe(2);
});

it('deixa montar o carrinho sem confirmar a quem não tem a permissão de confirmar pedidos', function (): void {
    $requester = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Requester)['membership'];
    Fixtures::fillCart($this->scenario, $requester, [$this->screw->id => '12']);

    ($this->page)(route('portal.cart'), $requester)
        ->assertOk()
        ->assertSeeText('a confirmação de pedidos é feita por um usuário da empresa com essa permissão');

    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)->call('placeOrder')->assertForbidden(), $requester);

    expect(($this->orders)())->toBe(0);
});

it('bloqueia a confirmação com item fora da regra de compra ou empresa impedida de comprar', function (): void {
    $this->scenario->system(fn () => CartItem::query()->whereKey($this->items[$this->screw->id])->firstOrFail()->forceFill(['quantity' => '13'])->save());

    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->assertSee('1 item precisa de ajuste antes da confirmação.')
        ->assertSee('Quantidade deve ser múltipla de 12 UN.')
        ->call('placeOrder')
        ->assertHasErrors(['checkout'])
        ->assertSee('Revise os itens do carrinho antes de confirmar.'));

    $this->scenario->system(fn () => CartItem::query()->whereKey($this->items[$this->screw->id])->firstOrFail()->forceFill(['quantity' => '12'])->save());
    $this->scenario->system(fn () => $this->buyer['company']->forceFill(['status' => BuyerCompanyStatus::Blocked])->save());

    ($this->asBuyer)(fn () => Livewire::test(CartPage::class)
        ->assertSee('não pode confirmar novos pedidos no momento')
        ->call('placeOrder')
        ->assertHasErrors(['checkout'])
        ->assertSee('A empresa compradora está bloqueada para compras.'));

    expect(($this->orders)())->toBe(0);
});

it('refaz a autorização nas ações do carrinho após a perda da permissão', function (): void {
    $component = ($this->asBuyer)(fn () => Livewire::test(CartPage::class)->assertOk());

    Catalog::revokePermission($this->scenario->tenant, SystemRole::Purchaser, Permission::PortalCart);

    ($this->asBuyer)(fn () => $component->call('removeItem', $this->items[$this->screw->id])->assertForbidden());

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toHaveCount(2);
});
