<?php

declare(strict_types=1);

use App\Data\Catalog\Portal\CatalogFilters;
use App\Data\Catalog\Portal\CatalogProductDetail;
use App\Data\Catalog\Portal\CatalogSku;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\Product;
use App\Services\Catalog\BuyerCatalog;
use App\Services\Catalog\BuyerCatalogScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Support\Builders\TenantScenario;

/*
| Visibilidade do catálogo do portal: o filtro explícito do serviço e a RLS
| aplicam o mesmo critério (produto ativo; público ou em catálogo privado
| ativo atribuído à empresa ou ao grupo; com SKU ativo).
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create(name: 'Distribuidora Serra Azul');
    $this->group = Catalog::group($this->tenant, 'FARMA', 'Redes de farmácias');
    $this->otherGroup = Catalog::group($this->tenant, 'MERCADO', 'Mercados de bairro');
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Farmácia Bom Jardim', 'customer_group_id' => $this->group->id]);
    $this->other = $this->tenant->addBuyerCompany(['trade_name' => 'Drogaria Vale Verde']);
    $this->buyer = $this->tenant->addBuyerUser($this->company);
    $this->otherBuyer = $this->tenant->addBuyerUser($this->other);

    /*
     * Cria o produto de um caso da matriz. Cada caso tem nome próprio para
     * que a listagem identifique exatamente o que foi exibido.
     */
    $this->scenario = function (string $case): Product {
        $tenant = $this->tenant;

        $restricted = static function (string $name) use ($tenant): Product {
            return Catalog::simpleProduct($tenant, $name, 'R-'.Str::upper(Str::random(6)), ['visibility' => ProductVisibility::Restricted]);
        };

        return match ($case) {
            'público ativo' => Catalog::simpleProduct($tenant, 'Luva nitrílica azul', 'LUV-001'),
            'restrito atribuído à empresa' => tap($restricted('Kit curativo hospitalar'), function (Product $product) use ($tenant): void {
                Catalog::assign($tenant, Catalog::privateCatalog($tenant, 'HOSP', [$product]), $this->company);
            }),
            'restrito atribuído ao grupo' => tap($restricted('Soro fisiológico 500 ml'), function (Product $product) use ($tenant): void {
                Catalog::assign($tenant, Catalog::privateCatalog($tenant, 'REDE', [$product]), $this->group);
            }),
            'restrito atribuído a outra empresa' => tap($restricted('Seringa descartável 10 ml'), function (Product $product) use ($tenant): void {
                Catalog::assign($tenant, Catalog::privateCatalog($tenant, 'VALE', [$product]), $this->other);
            }),
            'restrito atribuído a outro grupo' => tap($restricted('Termômetro digital'), function (Product $product) use ($tenant): void {
                Catalog::assign($tenant, Catalog::privateCatalog($tenant, 'MERC', [$product]), $this->otherGroup);
            }),
            'restrito em catálogo inativo' => tap($restricted('Máscara cirúrgica tripla'), function (Product $product) use ($tenant): void {
                Catalog::assign($tenant, Catalog::privateCatalog($tenant, 'ANTIGO', [$product], active: false), $this->company);
            }),
            'restrito sem catálogo' => $restricted('Avental descartável'),
            'rascunho' => Catalog::simpleProduct($tenant, 'Álcool em gel 70%', 'ALC-001', ['status' => ProductStatus::Draft]),
            'inativo' => Catalog::simpleProduct($tenant, 'Fita microporosa', 'FIT-001', ['status' => ProductStatus::Inactive]),
            'arquivado' => Catalog::simpleProduct($tenant, 'Gaze estéril', 'GAZ-001', ['status' => ProductStatus::Archived]),
            'apenas SKU inativo' => tap(Catalog::product($tenant, 'Esparadrapo branco'), static function (Product $product) use ($tenant): void {
                Catalog::sku($tenant, $product, 'ESP-001', ['status' => SkuStatus::Inactive]);
                Catalog::sku($tenant, $product, 'ESP-002', ['status' => SkuStatus::Archived]);
            }),
            default => throw new InvalidArgumentException("Caso desconhecido: {$case}"),
        };
    };
});

it('aplica ao comprador a matriz de visibilidade na listagem, no detalhe e no filtro explícito', function (string $case, bool $visible): void {
    $product = ($this->scenario)($case);

    $listed = Catalog::names(Catalog::browse($this->tenant, $this->buyer));
    $detail = fn (): CatalogProductDetail => $this->tenant->actingAs(
        $this->buyer,
        static fn (): CatalogProductDetail => app(BuyerCatalog::class)->product($product->id),
    );

    // Filtro explícito avaliado no contexto de sistema, em que a RLS não restringe
    // por empresa: comprova a defesa em profundidade independentemente do banco.
    $explicit = $this->tenant->system(fn (): array => app(BuyerCatalogScope::class)
        ->visibleProducts(Product::query(), $this->company)
        ->pluck('name')
        ->all());

    if ($visible) {
        expect($listed)->toBe([$product->name])
            ->and($detail()->id)->toBe($product->id)
            ->and($explicit)->toBe([$product->name]);
    } else {
        expect($listed)->toBe([])
            ->and($explicit)->toBe([])
            ->and($detail)->toThrow(ModelNotFoundException::class);
    }
})->with([
    'público ativo' => ['público ativo', true],
    'restrito em catálogo atribuído à empresa' => ['restrito atribuído à empresa', true],
    'restrito em catálogo atribuído ao grupo da empresa' => ['restrito atribuído ao grupo', true],
    'restrito em catálogo atribuído a outra empresa' => ['restrito atribuído a outra empresa', false],
    'restrito em catálogo atribuído a outro grupo' => ['restrito atribuído a outro grupo', false],
    'restrito em catálogo privado inativo' => ['restrito em catálogo inativo', false],
    'restrito sem catálogo privado' => ['restrito sem catálogo', false],
    'produto em rascunho' => ['rascunho', false],
    'produto inativo' => ['inativo', false],
    'produto arquivado' => ['arquivado', false],
    'produto apenas com SKUs inativos' => ['apenas SKU inativo', false],
]);

it('mostra a cada empresa apenas os catálogos privados atribuídos a ela', function (): void {
    foreach (['público ativo', 'restrito atribuído à empresa', 'restrito atribuído ao grupo', 'restrito atribuído a outra empresa'] as $case) {
        ($this->scenario)($case);
    }

    expect(Catalog::names(Catalog::browse($this->tenant, $this->buyer)))
        ->toBe(['Kit curativo hospitalar', 'Luva nitrílica azul', 'Soro fisiológico 500 ml'])
        ->and(Catalog::names(Catalog::browse($this->tenant, $this->otherBuyer)))
        ->toBe(['Luva nitrílica azul', 'Seringa descartável 10 ml']);
});

it('exibe somente os SKUs ativos de um produto visível', function (): void {
    $product = Catalog::product($this->tenant, 'Papel toalha interfolhado');
    Catalog::sku($this->tenant, $product, 'PTI-020', ['variant' => 'Fardo com 2.000 folhas']);
    Catalog::sku($this->tenant, $product, 'PTI-010', ['variant' => 'Fardo com 1.000 folhas', 'status' => SkuStatus::Inactive]);
    Catalog::sku($this->tenant, $product, 'PTI-005', ['variant' => 'Pacote com 500 folhas']);

    $page = Catalog::browse($this->tenant, $this->buyer);
    $detail = $this->tenant->actingAs($this->buyer, static fn (): CatalogProductDetail => app(BuyerCatalog::class)->product($product->id));
    $codes = static fn (array $skus): array => array_map(static fn (CatalogSku $sku): string => $sku->code, $skus);

    expect($codes($page->products->items()[0]->skus))->toBe(['PTI-005', 'PTI-020'])
        ->and($codes($detail->skus))->toBe(['PTI-005', 'PTI-020']);
});

it('trata como inexistente o produto de outro tenant e o identificador malformado', function (string $kind): void {
    $foreign = TenantScenario::create(name: 'Distribuidora Litoral Sul');
    $foreignProduct = Catalog::simpleProduct($foreign, 'Luva de procedimento', 'LUV-900');

    $id = $kind === 'outro tenant' ? $foreignProduct->id : 'LUV-900';

    expect(fn () => $this->tenant->actingAs($this->buyer, static fn () => app(BuyerCatalog::class)->product($id)))
        ->toThrow(ModelNotFoundException::class);
})->with(['outro tenant', 'identificador malformado']);

it('não lista produtos de outro tenant, mesmo com o mesmo nome', function (): void {
    $foreign = TenantScenario::create(name: 'Distribuidora Litoral Sul');
    Catalog::simpleProduct($foreign, 'Luva nitrílica azul', 'LUV-001');
    Catalog::simpleProduct($foreign, 'Touca descartável', 'TOU-001');
    Catalog::simpleProduct($this->tenant, 'Luva nitrílica azul', 'LUV-001');

    expect(Catalog::names(Catalog::browse($this->tenant, $this->buyer)))->toBe(['Luva nitrílica azul']);
});

it('exige a permissão de catálogo do portal no contexto de comprador', function (): void {
    Catalog::simpleProduct($this->tenant, 'Luva nitrílica azul', 'LUV-001');
    $staff = $this->tenant->addStaff(SystemRole::SalesManager);

    expect(fn () => $this->tenant->actingAs($staff, static fn () => app(BuyerCatalog::class)->browse(new CatalogFilters)))
        ->toThrow(AuthorizationException::class);

    Catalog::revokePermission($this->tenant, SystemRole::Purchaser, Permission::PortalCatalog);

    expect(fn () => Catalog::browse($this->tenant, $this->buyer))
        ->toThrow(AuthorizationException::class, 'Permissão necessária: Ver catálogo e preços.');
});
