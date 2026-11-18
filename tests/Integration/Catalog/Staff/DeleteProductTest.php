<?php

declare(strict_types=1);

use App\Actions\Catalog\DeleteProduct;
use App\Domain\Catalog\Exceptions\ProductHasHistory;
use App\Domain\Shared\Money;
use App\Enums\Catalog\MediaKind;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogProduct;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCommercialPolicy;
use App\Models\Catalog\SkuCost;
use App\Models\Identity\Membership;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Storage::fake('private');

    $this->delete = fn (TenantScenario $tenant, Membership $actor, Product $product): mixed => $tenant->actingAs(
        $actor,
        static fn () => app(DeleteProduct::class)->handle($product),
    );

    $this->exists = fn (TenantScenario $tenant, Product $product): bool => $tenant->system(
        static fn (): bool => Product::query()->whereKey($product->id)->exists(),
    );

    $this->attachFile = fn (TenantScenario $tenant, Product $product): ProductMedia => $tenant->system(static function () use ($tenant, $product): ProductMedia {
        $id = (string) Str::uuid7();
        $path = "tenants/{$tenant->organization->id}/products/{$product->id}/{$id}.pdf";
        Storage::disk('private')->put($path, "%PDF-1.4\nficha técnica\n%%EOF");

        $media = new ProductMedia;
        $media->forceFill([
            'id' => $id,
            'product_id' => $product->id,
            'kind' => MediaKind::Document,
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'ficha-tecnica.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 30,
            'sha256' => hash('sha256', 'ficha'),
        ])->save();

        return $media;
    });
});

it('exclui produto sem histórico com SKUs, política, mídias e vínculos de catálogo privado', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit, ['name' => 'Toalha de mesa descartável']);
    $sku = Fixtures::sku($tenant, $product, $unit, 'TOA-140');
    $media = ($this->attachFile)($tenant, $product);
    $tenant->system(static function () use ($sku, $product): void {
        (new SkuCommercialPolicy)->forceFill(['sku_id' => $sku->id, 'min_unit_price_cents' => Money::ofCents(990)])->save();
        $catalog = new PrivateCatalog;
        $catalog->forceFill(['code' => 'EVENTOS', 'name' => 'Clientes de eventos'])->save();
        (new PrivateCatalogProduct)->forceFill(['private_catalog_id' => $catalog->id, 'product_id' => $product->id])->save();
    });

    $fileExistedBeforeCommit = $tenant->actingAs($tenant->ownerMembership, static function () use ($product, $media): bool {
        app(DeleteProduct::class)->handle($product);

        // O arquivo só é apagado depois do commit da transação externa.
        return Storage::disk('private')->exists($media->path);
    });

    expect($fileExistedBeforeCommit)->toBeTrue()
        ->and(Storage::disk('private')->exists($media->path))->toBeFalse()
        ->and(($this->exists)($tenant, $product))->toBeFalse()
        ->and($tenant->system(static fn (): array => [
            Sku::query()->count(),
            SkuCommercialPolicy::query()->count(),
            ProductMedia::query()->count(),
            PrivateCatalogProduct::query()->count(),
        ]))->toBe([0, 0, 0, 0]);

    expect(Fixtures::audits($tenant, 'product.deleted')->sole()->data)->toEqual([
        'name' => 'Toalha de mesa descartável',
        'slug' => $product->slug,
        'skus' => ['TOA-140'],
        'media_files' => 1,
    ]);
});

it('recusa excluir produto com SKU em tabela de preço e recomenda arquivar', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit);
    $sku = Fixtures::sku($tenant, $product, $unit, 'CAF-1KG');
    $media = ($this->attachFile)($tenant, $product);
    $tenant->system(static function () use ($sku): void {
        $list = new PriceList;
        $list->forceFill([
            'code' => 'PADRAO',
            'name' => 'Tabela padrão',
            'scope' => PriceListScope::Default,
            'valid_from' => Fixtures::today(),
            'status' => PriceListStatus::Active,
        ])->save();
        (new PriceListItem)->forceFill(['price_list_id' => $list->id, 'sku_id' => $sku->id, 'unit_price_cents' => Money::ofCents(4590)])->save();
    });

    // Na mesma transação externa, a falha fica restrita ao savepoint: a
    // transação continua utilizável depois da recusa.
    [$error, $stillUsable] = $tenant->actingAs($tenant->ownerMembership, static function () use ($product): array {
        try {
            app(DeleteProduct::class)->handle($product);
        } catch (ProductHasHistory $e) {
            return [$e, Product::query()->whereKey($product->id)->exists()];
        }

        return [null, false];
    });

    expect($error)->toBeInstanceOf(ProductHasHistory::class)
        ->and($error->errorCode())->toBe('product_has_history')
        ->and($error->details())->toBe(['recommendation' => 'archive'])
        ->and($error->getMessage())->toContain('Arquive-o')
        ->and($stillUsable)->toBeTrue()
        ->and(($this->exists)($tenant, $product))->toBeTrue()
        ->and($tenant->system(static fn (): int => Sku::query()->count()))->toBe(1)
        ->and(Storage::disk('private')->exists($media->path))->toBeTrue()
        ->and(Fixtures::audits($tenant, 'product.deleted'))->toBeEmpty();
});

it('recusa excluir produto com histórico de custo', function (): void {
    $tenant = TenantScenario::create();
    $unit = Fixtures::unit($tenant, 'UN');
    $product = Fixtures::product($tenant, $unit);
    $sku = Fixtures::sku($tenant, $product, $unit, 'CAF-1KG');
    $tenant->system(static function () use ($sku): void {
        $cost = new SkuCost;
        $cost->timestamps = false;
        $cost->forceFill(['sku_id' => $sku->id, 'cost_cents' => Money::ofCents(2150), 'effective_from' => now()])->save();
    });

    expect(fn () => ($this->delete)($tenant, $tenant->ownerMembership, $product))->toThrow(ProductHasHistory::class);
    expect(($this->exists)($tenant, $product))->toBeTrue();
});

it('exige catalog.manage', function (SystemRole $role): void {
    $tenant = TenantScenario::create();
    $product = Fixtures::product($tenant, Fixtures::unit($tenant, 'UN'));

    expect(fn () => ($this->delete)($tenant, $tenant->addStaff($role), $product))->toThrow(AuthorizationException::class);
    expect(($this->exists)($tenant, $product))->toBeTrue();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'consulta' => [SystemRole::Viewer],
]);

it('não exclui produto de outro tenant', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $foreign = Fixtures::product($other, Fixtures::unit($other, 'UN'));

    expect(fn () => ($this->delete)($tenant, $tenant->ownerMembership, $foreign))->toThrow(ModelNotFoundException::class);
    expect(($this->exists)($other, $foreign))->toBeTrue();
});
