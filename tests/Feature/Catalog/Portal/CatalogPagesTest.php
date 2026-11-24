<?php

declare(strict_types=1);

use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Livewire\Portal\Catalog\CatalogIndex;
use App\Livewire\Portal\Catalog\ProductDetail;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Support\Builders\TenantScenario;

/*
| Telas do catálogo do portal (portal.catalog e portal.catalog.product):
| acesso por permissão e contexto, identificadores adulterados, busca,
| categoria, paginação e conferência de quantidade com preço.
*/

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('private');

    $this->tenant = TenantScenario::create(name: 'Distribuidora Vale do Aço');
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Horizonte']);
    $this->buyer = $this->tenant->addBuyerUser($this->company);

    $structural = Catalog::category($this->tenant, 'Materiais estruturais');
    $finishing = Catalog::category($this->tenant, 'Acabamento');

    $this->cement = Catalog::product($this->tenant, 'Cimento CP II 50 kg', [
        'brand' => 'Pedra Forte',
        'description' => 'Cimento Portland composto para uso geral em obras.',
        'category' => $structural,
    ]);
    $this->cementSku = Catalog::sku($this->tenant, $this->cement, 'CIM-50', [
        'minimum' => '10',
        'multiple' => '10',
        'package' => 'Saco 50 kg',
        'attributes' => ['Resistência' => '32 MPa'],
    ]);
    Catalog::priceList($this->tenant, PriceListScope::Default, [$this->cementSku->id => 3_890], [
        'tiers' => [$this->cementSku->id => [['100', 3_590]]],
    ]);
    Catalog::internalCosts($this->tenant, $this->cementSku, costCents: 2_731, minimumPriceCents: 3_457, minimumMarginBps: 1_500);
    $this->image = Catalog::media($this->tenant, $this->cement, MediaKind::Image, 'saco-cimento.png', 'image/png', 'imagem do saco');
    $this->document = Catalog::media($this->tenant, $this->cement, MediaKind::Document, 'Ficha técnica CP II.pdf', 'application/pdf', '%PDF ficha');
    $this->internalDocument = Catalog::media($this->tenant, $this->cement, MediaKind::Document, 'Planilha de custos internos.xlsx', 'application/vnd.ms-excel', 'interno', visibleToBuyers: false);

    $this->sand = Catalog::product($this->tenant, 'Areia média lavada', ['category' => $finishing]);
    Catalog::sku($this->tenant, $this->sand, 'ARE-M', ['unit' => 'M', 'minimum' => '0,5', 'multiple' => '0,5']);

    $this->hidden = Catalog::simpleProduct($this->tenant, 'Aditivo impermeabilizante', 'ADT-18', ['visibility' => ProductVisibility::Restricted]);

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->buyer;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get($url);
    };

    $this->asBuyer = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->buyer;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
});

it('exibe o catálogo ao comprador com preços, origem e preço sob consulta, sem itens ocultos ou dados internos', function (): void {
    ($this->page)(route('portal.catalog'))
        ->assertOk()
        ->assertSeeText('Catálogo')
        ->assertSeeText('Cimento CP II 50 kg')
        ->assertSeeText('Pedra Forte · Materiais estruturais')
        ->assertSeeText('R$ 38,90')
        ->assertSeeText('Tabela padrão · para 10 UN')
        ->assertSeeText('Mínimo 10 UN · múltiplos de 10 UN')
        ->assertSeeText('Areia média lavada')
        ->assertSeeText('Preço sob consulta')
        ->assertSee(route('portal.media.download', $this->image->id), escape: false)
        ->assertSee(route('portal.catalog.product', $this->cement->id), escape: false)
        ->assertDontSeeText('Aditivo impermeabilizante')
        ->assertDontSeeText('27,31')
        ->assertDontSeeText('34,57')
        ->assertDontSeeText('Planilha de custos internos');
});

it('inclui o catálogo no menu do portal', function (): void {
    ($this->page)(route('portal.home'))
        ->assertOk()
        ->assertSee(route('portal.catalog'), escape: false);
});

it('nega as telas do catálogo ao comprador sem a permissão', function (string $route): void {
    Catalog::revokePermission($this->tenant, SystemRole::Purchaser, Permission::PortalCatalog);

    ($this->page)($route === 'portal.catalog' ? route($route) : route($route, $this->cement->id))->assertForbidden();
})->with(['portal.catalog', 'portal.catalog.product']);

it('nega o catálogo do portal no contexto da equipe do distribuidor', function (): void {
    $staff = $this->tenant->addStaff(SystemRole::Viewer);

    ($this->page)(route('portal.catalog'), $staff)->assertForbidden();
    ($this->page)(route('portal.catalog.product', $this->cement->id), $staff)->assertForbidden();
});

it('exibe a página do produto com variação, regra de compra, preço, imagens e documentos', function (): void {
    ($this->page)(route('portal.catalog.product', $this->cement->id))
        ->assertOk()
        ->assertSeeText('Cimento Portland composto para uso geral em obras.')
        ->assertSeeText('CIM-50')
        ->assertSeeText('Saco 50 kg')
        ->assertSeeText('Resistência:')
        ->assertSeeText('32 MPa')
        ->assertSeeText('Quantidade em UN')
        ->assertSeeText('Total para 10 UN: R$ 389,00')
        ->assertSee('alt="Cimento CP II 50 kg, imagem 1 de 1"', escape: false)
        ->assertSee(route('portal.media.download', $this->document->id), escape: false)
        ->assertSeeText('Ficha técnica CP II.pdf')
        ->assertDontSeeText('Planilha de custos internos')
        ->assertDontSee(route('portal.media.download', $this->internalDocument->id), escape: false)
        ->assertDontSeeText('27,31');
});

it('responde 404 à página de produto oculto, de outro tenant ou com identificador malformado', function (string $case): void {
    $id = match ($case) {
        'restrito não atribuído' => $this->hidden->id,
        'outro tenant' => Catalog::simpleProduct(TenantScenario::create(name: 'Distribuidora Litoral'), 'Cimento CP II 50 kg', 'CIM-50')->id,
        'malformado' => 'CIM-50',
    };

    ($this->page)(route('portal.catalog.product', $id))->assertNotFound();
})->with(['restrito não atribuído', 'outro tenant', 'malformado']);

it('filtra a listagem por busca e categoria e limpa os filtros', function (): void {
    $structuralId = $this->tenant->system(fn (): string => $this->cement->fresh()->category_id);

    ($this->asBuyer)(static fn () => Livewire::test(CatalogIndex::class)
        ->assertSee('Cimento CP II 50 kg')
        ->assertSee('Areia média lavada')
        ->set('search', 'pedra forte')
        ->assertSee('Cimento CP II 50 kg')
        ->assertDontSee('Areia média lavada')
        ->set('search', 'inexistente')
        ->assertSee('Nenhum produto encontrado')
        ->assertSee('Limpar filtros')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSee('Areia média lavada')
        ->set('category', $structuralId)
        ->assertSee('Cimento CP II 50 kg')
        ->assertDontSee('Areia média lavada'));
});

it('ignora categoria adulterada ou sem produtos visíveis', function (string $category): void {
    ($this->asBuyer)(static fn () => Livewire::test(CatalogIndex::class)
        ->set('category', $category)
        ->assertSet('category', '')
        ->assertSee('Cimento CP II 50 kg')
        ->assertSee('Areia média lavada'));
})->with([
    'texto qualquer' => ['materiais'],
    'UUID inexistente' => ['01970000-0000-7000-8000-000000000000'],
]);

it('pagina a listagem pelo componente', function (): void {
    foreach (range(1, 30) as $n) {
        Catalog::simpleProduct($this->tenant, sprintf('Bloco cerâmico %02d furos', $n), sprintf('BLC-%02d', $n));
    }

    ($this->asBuyer)(static fn () => Livewire::test(CatalogIndex::class)
        ->assertSee('Exibindo 1 a 24 de 32 produtos')
        ->assertSee('Bloco cerâmico 01 furos')
        ->assertDontSee('Cimento CP II 50 kg')
        ->call('gotoPage', 2)
        ->assertSee('Exibindo 25 a 32 de 32 produtos')
        ->assertSee('Cimento CP II 50 kg')
        ->assertDontSee('Bloco cerâmico 01 furos')
        ->set('search', 'cimento')
        ->assertSee('Cimento CP II 50 kg')
        ->assertDontSee('Exibindo'));
});

it('confere a quantidade digitada, sugere a válida mais próxima e mostra o preço dela', function (): void {
    $skuId = $this->cementSku->id;

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->cement->id])
        ->assertSee('Preço calculado para a quantidade mínima de compra.')
        ->set("quantities.{$skuId}", '15')
        ->assertSee('Este item é vendido em múltiplos de 10 UN.')
        ->assertSee('Quantidade válida mais próxima:')
        ->assertSee('Total para 20 UN:')
        ->assertSee('R$ 778,00')
        ->call('applySuggestion', $skuId)
        ->assertSet("quantities.{$skuId}", '20')
        ->assertSee('Quantidade válida.')
        ->set("quantities.{$skuId}", '5')
        ->assertSee('A quantidade mínima de compra é 10 UN.')
        ->set("quantities.{$skuId}", 'dez sacos')
        ->assertSee('Informe a quantidade em números, por exemplo 12 ou 2,5.')
        ->assertSee('Total para 10 UN:')
        ->set("quantities.{$skuId}", '100')
        ->assertSee('Quantidade válida.')
        ->assertSee('R$ 35,90')
        ->assertSee('Preço por volume a partir de 100 UN')
        ->assertSee('R$ 3.590,00'));
});

it('confere casas decimais pela unidade de venda', function (): void {
    $skuId = $this->tenant->system(fn (): string => $this->sand->skus()->value('id'));

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->sand->id])
        ->set("quantities.{$skuId}", '1,255')
        ->assertSee('A unidade M aceita no máximo 2 casas decimais.')
        ->assertSee('Este item é vendido em múltiplos de 0,5 M.')
        ->assertSee('Quantidade válida mais próxima:')
        ->assertSee('1,5 M')
        ->set("quantities.{$skuId}", '2,5')
        ->assertSee('Quantidade válida.')
        ->assertSee('Preço sob consulta'));
});

it('protege o produto da tela contra alteração pelo cliente', function (): void {
    ($this->asBuyer)(function (): void {
        $component = Livewire::test(ProductDetail::class, ['product' => $this->cement->id]);

        expect(fn () => $component->set('productId', $this->hidden->id))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        $component->assertSee('Cimento CP II 50 kg');
    });
});

it('trata como inexistente o SKU adulterado ao aplicar a sugestão', function (string $case): void {
    $foreignSku = match ($case) {
        'SKU de outro produto' => $this->tenant->system(fn (): string => $this->sand->skus()->value('id')),
        'SKU de outro tenant' => Catalog::sku(
            $other = TenantScenario::create(name: 'Distribuidora Litoral'),
            Catalog::product($other, 'Cimento CP V 40 kg'),
            'CIM-40',
        )->id,
        'identificador malformado' => 'CIM-50',
    };

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->cement->id])
        ->set("quantities.{$this->cementSku->id}", '15')
        ->call('applySuggestion', $foreignSku)
        ->assertNotFound());
})->with(['SKU de outro produto', 'SKU de outro tenant', 'identificador malformado']);

it('descarta chaves de quantidade que não identificam SKU', function (): void {
    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->cement->id])
        ->set('quantities.campo-extra', '999')
        ->assertSet('quantities', []));
});

it('refaz a autorização nas ações do componente após a perda da permissão', function (): void {
    $component = ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->cement->id])->assertOk());

    Catalog::revokePermission($this->tenant, SystemRole::Purchaser, Permission::PortalCatalog);

    ($this->asBuyer)(fn () => $component->set("quantities.{$this->cementSku->id}", '20')->assertForbidden());
});

it('reaplica a permissão nas atualizações do Livewire pelo endpoint real', function (): void {
    $page = ($this->page)(route('portal.catalog.product', $this->cement->id))->assertOk();
    $snapshot = Tests\Support\LivewireSnapshot::page($page);

    $update = function () use ($snapshot): TestResponse {
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($this->buyer->user_id))
            ->withSession(TenantScenario::sessionFor($this->buyer))
            ->json('POST', app(HandleRequests::class)->getUpdateUri(), [
                'components' => [[
                    'snapshot' => $snapshot,
                    'updates' => ["quantities.{$this->cementSku->id}" => '15'],
                    'calls' => [],
                ]],
            ], ['X-Livewire' => '1', 'Accept' => '*/*']);
    };

    expect((string) $update()->assertOk()->json('components.0.effects.html'))->toContain('Este item é vendido em múltiplos de 10 UN.');

    Catalog::revokePermission($this->tenant, SystemRole::Purchaser, Permission::PortalCatalog);

    $update()->assertForbidden();
});

it('trata parâmetros de URL adulterados como ausentes', function (array $query): void {
    ($this->page)(route('portal.catalog', $query))
        ->assertOk()
        ->assertSeeText('Cimento CP II 50 kg')
        ->assertSeeText('Areia média lavada');
})->with([
    'busca como lista' => [['busca' => ['cimento', 'areia']]],
    'categoria como lista' => [['categoria' => ['x']]],
    'página não numérica' => [['page' => 'ultima']],
    'página como lista' => [['page' => ['2']]],
    'página além da última' => [['page' => '99']],
]);

it('aplica a busca informada na URL', function (): void {
    ($this->page)(route('portal.catalog', ['busca' => 'areia']))
        ->assertOk()
        ->assertSeeText('Areia média lavada')
        ->assertDontSeeText('Cimento CP II 50 kg');
});

it('não calcula total acima do limite e não falha com quantidades extremas', function (): void {
    $skuId = $this->cementSku->id;
    // R$ 50.000,00 por unidade, inclusive na faixa por volume.
    $this->tenant->system(static function () use ($skuId): void {
        Illuminate\Support\Facades\DB::table('price_list_items')->where('sku_id', $skuId)->update(['unit_price_cents' => 5_000_000]);
        Illuminate\Support\Facades\DB::table('price_tiers')->update(['unit_price_cents' => 5_000_000]);
    });

    ($this->asBuyer)(fn () => Livewire::test(ProductDetail::class, ['product' => $this->cement->id])
        ->set("quantities.{$skuId}", '300.000.000')
        ->assertOk()
        ->assertSee('Total acima do limite de cálculo para 300.000.000 UN.')
        ->set("quantities.{$skuId}", '-1000000000000000')
        ->assertOk()
        ->assertSee('Informe uma quantidade maior que zero.'));
});
