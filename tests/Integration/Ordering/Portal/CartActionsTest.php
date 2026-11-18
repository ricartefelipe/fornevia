<?php

declare(strict_types=1);

use App\Actions\Ordering\Portal\AddToCart;
use App\Actions\Ordering\Portal\CurrentCart;
use App\Actions\Ordering\Portal\RemoveCartItem;
use App\Actions\Ordering\Portal\UpdateCartItem;
use App\Data\Ordering\Portal\CartView;
use App\Domain\Ordering\Exceptions\InvalidCartItem;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\SystemRole;
use App\Models\Ordering\Cart;
use App\Models\Ordering\CartItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Integration\Ordering\Portal\PortalOrderingFixtures as Fixtures;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Carrinho do portal (RF-060): inclusão, alteração e remoção de itens pelo
| comprador, com regra de compra, preço vigente e limites conferidos no
| servidor; isolamento por associação, empresa e tenant.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 2_500);
    $this->buyer = Fixtures::buyer($this->scenario);
    $this->boxed = Fixtures::sku($this->scenario, 'PAR-CX12', priceCents: 300, options: ['minimum' => '12', 'multiple' => '12'], name: 'Parafuso sextavado');

    $this->add = fn (string $skuId, string $quantity, ?array $buyer = null) => Fixtures::as(
        $this->scenario,
        ($buyer ?? $this->buyer)['membership'],
        static fn (): CartItem => app(AddToCart::class)->handle($skuId, Quantity::of($quantity)),
    );
    $this->view = fn (?array $buyer = null): CartView => Fixtures::as(
        $this->scenario,
        ($buyer ?? $this->buyer)['membership'],
        static fn (): CartView => app(CurrentCart::class)->handle(),
    );
});

it('cria o carrinho na primeira inclusão e soma quantidades do mesmo SKU', function (): void {
    ($this->add)($this->scenario->sku->id, '2');
    ($this->add)($this->scenario->sku->id, '3');
    ($this->add)($this->boxed->id, '24');

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))
        ->toBe([$this->scenario->sku->code => '5', 'PAR-CX12' => '24'])
        ->and($this->scenario->system(fn (): int => Cart::query()->where('membership_id', $this->buyer['membership']->id)->count()))->toBe(1);
});

it('exibe o carrinho com preços do servidor, disponibilidade e totais', function (): void {
    ($this->add)($this->scenario->sku->id, '4');
    ($this->add)($this->boxed->id, '12');

    $view = ($this->view)();

    expect($view->isReady())->toBeTrue()
        ->and($view->totals?->total->cents)->toBe(4 * 2_500 + 12 * 300)
        ->and(array_map(static fn ($line): string => $line->availability, $view->lines))->toBe(['available', 'available'])
        ->and($view->lines[0]->amounts?->unitPrice->cents)->toBe(2_500);
});

it('devolve carrinho vazio sem criar registro quando não há carrinho aberto', function (): void {
    $view = ($this->view)();

    expect($view->isEmpty())->toBeTrue()
        ->and($view->cartId)->toBeNull()
        ->and($this->scenario->system(static fn (): int => Cart::query()->count()))->toBe(0);
});

it('recusa quantidade fora da regra de compra com a sugestão válida mais próxima', function (): void {
    try {
        ($this->add)($this->boxed->id, '13');
        $this->fail('Deveria recusar a quantidade.');
    } catch (InvalidCartItem $e) {
        expect($e->getMessage())->toContain('Este item é vendido em múltiplos de 12 UN.')
            ->and($e->suggestion()?->format())->toBe('24');
    }

    expect(fn () => ($this->add)($this->boxed->id, '6'))->toThrow(InvalidCartItem::class, 'A quantidade mínima de compra é 12 UN.')
        ->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([]);
});

it('recusa SKU sem preço vigente e total acima do limite de cálculo', function (): void {
    $unpriced = Fixtures::sku($this->scenario, 'SEM-PRECO', priceCents: null);
    $expensive = Fixtures::sku($this->scenario, 'TRAFO-1MVA', priceCents: 900_000_000_000);

    expect(fn () => ($this->add)($unpriced->id, '1'))->toThrow(InvalidCartItem::class, 'sem preço vigente')
        ->and(fn () => ($this->add)($expensive->id, '999999'))->toThrow(InvalidCartItem::class, 'excede o limite de cálculo')
        ->and(fn () => ($this->add)($this->scenario->sku->id, '999999999'))->not->toThrow(InvalidCartItem::class)
        ->and(fn () => ($this->add)($this->scenario->sku->id, '1'))->toThrow(InvalidCartItem::class, 'excede o limite de 999.999.999 UN por item');
});

it('desfaz a inclusão quando o total do carrinho excederia o limite de cálculo', function (): void {
    $big = Fixtures::sku($this->scenario, 'GER-500', priceCents: 1_000_000_000);
    $other = Fixtures::sku($this->scenario, 'GER-501', priceCents: 1_000_000_000);

    ($this->add)($big->id, '900000');

    expect(fn () => ($this->add)($other->id, '200000'))->toThrow(InvalidCartItem::class, 'O total do carrinho excederia o limite de cálculo')
        ->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe(['GER-500' => '900.000']);
});

it('trata como inexistente SKU restrito, de outro tenant, inativo ou malformado', function (string $case): void {
    $skuId = match ($case) {
        'restrito' => Fixtures::sku($this->scenario, 'RES-1', options: ['visibility' => ProductVisibility::Restricted])->id,
        'outro tenant' => Catalog::sku($other = TenantScenario::create(), Catalog::product($other, 'Cabo de outro tenant'), 'CAB-X')->id,
        'inativo' => tap(Fixtures::sku($this->scenario, 'INA-1'), fn ($sku) => $this->scenario->system(static fn () => DB::table('skus')->where('id', $sku->id)->update(['status' => 'inactive'])))->id,
        'malformado' => 'PAR-CX12',
        'inexistente' => (string) Str::uuid7(),
    };

    expect(fn () => ($this->add)($skuId, '1'))->toThrow(ModelNotFoundException::class);
})->with(['restrito', 'outro tenant', 'inativo', 'malformado', 'inexistente']);

it('altera a quantidade com entrada localizada e recusa a inválida', function (): void {
    $item = ($this->add)($this->boxed->id, '12');
    $update = fn (string $id, string $quantity, ?array $buyer = null) => Fixtures::as(
        $this->scenario,
        ($buyer ?? $this->buyer)['membership'],
        static fn (): CartItem => app(UpdateCartItem::class)->handle($id, $quantity),
    );

    expect($update($item->id, '1.200')->quantity->toDatabase())->toBe('1200.000')
        ->and(fn () => $update($item->id, 'doze'))->toThrow(InvalidCartItem::class, 'Informe a quantidade em números')
        ->and(fn () => $update($item->id, '30'))->toThrow(InvalidCartItem::class, 'Quantidade válida mais próxima: 36 UN.')
        ->and(fn () => $update(strtoupper($item->id), '24'))->not->toThrow(Throwable::class)
        ->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe(['PAR-CX12' => '24']);
});

it('pede a remoção de item que deixou de estar disponível', function (): void {
    $item = ($this->add)($this->boxed->id, '12');
    $this->scenario->system(fn () => DB::table('products')->where('id', $this->boxed->product_id)->update(['visibility' => 'restricted']));

    expect(fn () => Fixtures::as($this->scenario, $this->buyer['membership'], static fn () => app(UpdateCartItem::class)->handle($item->id, '24')))
        ->toThrow(InvalidCartItem::class, 'Remova-o do carrinho');

    $view = ($this->view)();

    expect($view->isReady())->toBeFalse()
        ->and($view->lines[0]->problems)->toBe(['O item não está mais disponível para a sua empresa.']);

    Fixtures::as($this->scenario, $this->buyer['membership'], static fn () => app(RemoveCartItem::class)->handle($item->id));

    expect(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([]);
});

it('isola o carrinho por associação e por empresa', function (string $who): void {
    $item = ($this->add)($this->scenario->sku->id, '2');
    $other = match ($who) {
        'colega da mesma empresa' => Fixtures::buyer($this->scenario, $this->buyer['company']),
        'usuário de outra empresa' => Fixtures::buyer($this->scenario),
    };

    expect(fn () => Fixtures::as($this->scenario, $other['membership'], static fn () => app(UpdateCartItem::class)->handle($item->id, '5')))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => Fixtures::as($this->scenario, $other['membership'], static fn () => app(RemoveCartItem::class)->handle($item->id)))
        ->toThrow(ModelNotFoundException::class)
        ->and(($this->view)($other)->isEmpty())->toBeTrue()
        ->and(Fixtures::cartLines($this->scenario, $this->buyer['membership']))->toBe([$this->scenario->sku->code => '2']);
})->with(['colega da mesma empresa', 'usuário de outra empresa']);

it('não alcança carrinho de outro tenant nem pelo banco', function (): void {
    $item = ($this->add)($this->scenario->sku->id, '2');
    $foreign = OrderingScenario::create();
    $stranger = Fixtures::buyer($foreign);

    expect(fn () => Fixtures::as($foreign, $stranger['membership'], static fn () => app(RemoveCartItem::class)->handle($item->id)))
        ->toThrow(ModelNotFoundException::class)
        ->and(Fixtures::as($foreign, $stranger['membership'], static fn (): int => DB::table('cart_items')->count()))->toBe(0);
});

it('exige a permissão de carrinho e o contexto de comprador', function (): void {
    $approver = Fixtures::buyer($this->scenario, $this->buyer['company'], SystemRole::Approver);
    $staff = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    expect(fn () => ($this->add)($this->scenario->sku->id, '1', $approver))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->view)($approver))->toThrow(AuthorizationException::class)
        ->and(fn () => Fixtures::as($this->scenario, $staff, fn () => app(AddToCart::class)->handle($this->scenario->sku->id, Quantity::of('1'))))
        ->toThrow(AuthorizationException::class);
});

it('mostra as linhas sem preço quando o total excede o limite de cálculo', function (): void {
    $big = Fixtures::sku($this->scenario, 'GER-900', priceCents: 1_000_000_000);
    Fixtures::fillCart($this->scenario, $this->buyer['membership'], [$big->id => '900000', $this->scenario->sku->id => '1']);
    Fixtures::setPrice($this->scenario, $big, 2_000_000_000);

    $view = ($this->view)();

    expect($view->overLimit)->toBeTrue()
        ->and($view->isReady())->toBeFalse()
        ->and($view->totals)->toBeNull()
        ->and($view->lines[0]->problems)->toBe(['O total excede o limite de cálculo. Reduza a quantidade.']);
});
