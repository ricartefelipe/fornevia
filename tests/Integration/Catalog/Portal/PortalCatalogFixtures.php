<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog\Portal;

use App\Data\Catalog\Portal\CatalogFilters;
use App\Data\Catalog\Portal\CatalogPage;
use App\Data\Catalog\Portal\CatalogProduct;
use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\Category;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Models\Catalog\PrivateCatalogProduct;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCommercialPolicy;
use App\Models\Catalog\SkuCost;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Models\Pricing\NegotiatedPrice;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier;
use App\Services\Catalog\BuyerCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/**
 * Monta catálogo, catálogos privados, preços e mídias de um tenant no
 * contexto de sistema (como faria a equipe do distribuidor), para os testes
 * do catálogo do portal.
 */
final class PortalCatalogFixtures
{
    public static function group(TenantScenario $tenant, string $code, string $name): CustomerGroup
    {
        return $tenant->system(static fn (): CustomerGroup => CustomerGroup::query()->create(['code' => $code, 'name' => $name]));
    }

    public static function category(TenantScenario $tenant, string $name, ?Category $parent = null, int $sortOrder = 0): Category
    {
        return $tenant->system(static function () use ($name, $parent, $sortOrder): Category {
            $category = new Category;
            $category->forceFill([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(5)),
                'sort_order' => $sortOrder,
                'parent_id' => $parent?->id,
            ])->save();

            return $category;
        });
    }

    /**
     * @param  array{status?: ProductStatus, visibility?: ProductVisibility, brand?: string|null, description?: string|null, category?: Category|null}  $options
     */
    public static function product(TenantScenario $tenant, string $name, array $options = []): Product
    {
        return $tenant->system(static function () use ($name, $options): Product {
            $status = $options['status'] ?? ProductStatus::Active;
            $product = new Product;
            $product->forceFill([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'brand' => $options['brand'] ?? null,
                'description' => $options['description'] ?? null,
                'category_id' => ($options['category'] ?? null)?->id,
                'base_unit_id' => UnitOfMeasure::query()->where('code', 'UN')->value('id'),
                'status' => $status,
                'visibility' => $options['visibility'] ?? ProductVisibility::Public,
                'archived_at' => $status === ProductStatus::Archived ? now() : null,
            ])->save();

            return $product;
        });
    }

    /**
     * @param  array{unit?: string, minimum?: string, multiple?: string, status?: SkuStatus, variant?: string|null, package?: string|null, attributes?: array<string, string>}  $options
     */
    public static function sku(TenantScenario $tenant, Product $product, string $code, array $options = []): Sku
    {
        return $tenant->system(static function () use ($product, $code, $options): Sku {
            $sku = new Sku;
            $sku->forceFill([
                'product_id' => $product->id,
                'sales_unit_id' => UnitOfMeasure::query()->where('code', $options['unit'] ?? 'UN')->value('id'),
                'code' => $code,
                'variant_label' => $options['variant'] ?? null,
                'package_label' => $options['package'] ?? null,
                'min_order_quantity' => $options['minimum'] ?? '1',
                'order_multiple' => $options['multiple'] ?? '1',
                'status' => $options['status'] ?? SkuStatus::Active,
            ]);

            // Atributos vazios permanecem como objeto JSON (padrão da coluna).
            if (($options['attributes'] ?? []) !== []) {
                $sku->forceFill(['attributes' => $options['attributes']]);
            }

            $sku->save();

            return $sku;
        });
    }

    /**
     * Produto público ativo com um SKU vendido por unidade.
     */
    public static function simpleProduct(TenantScenario $tenant, string $name, string $code, array $productOptions = []): Product
    {
        $product = self::product($tenant, $name, $productOptions);
        self::sku($tenant, $product, $code);

        return $product;
    }

    /**
     * @param  list<Product>  $products
     */
    public static function privateCatalog(TenantScenario $tenant, string $code, array $products, bool $active = true): PrivateCatalog
    {
        return $tenant->system(static function () use ($code, $products, $active): PrivateCatalog {
            $catalog = PrivateCatalog::query()->create([
                'code' => $code,
                'name' => 'Linha '.$code,
                'is_active' => $active,
            ]);

            foreach ($products as $product) {
                (new PrivateCatalogProduct)->forceFill(['private_catalog_id' => $catalog->id, 'product_id' => $product->id])->save();
            }

            return $catalog;
        });
    }

    public static function assign(TenantScenario $tenant, PrivateCatalog $catalog, BuyerCompany|CustomerGroup $target): void
    {
        $tenant->system(static function () use ($catalog, $target): void {
            (new PrivateCatalogAssignment)->forceFill([
                'private_catalog_id' => $catalog->id,
                'buyer_company_id' => $target instanceof BuyerCompany ? $target->id : null,
                'customer_group_id' => $target instanceof CustomerGroup ? $target->id : null,
            ])->save();
        });
    }

    /**
     * Tabela de preço ativa com itens (sku_id => centavos) e faixas
     * opcionais (sku_id => [[quantidade mínima, centavos], ...]).
     *
     * @param  array<string, int>  $prices
     * @param  array{target?: BuyerCompany|CustomerGroup|null, priority?: int, status?: PriceListStatus, valid_from?: string, valid_until?: string|null, tiers?: array<string, list<array{0: string, 1: int}>>}  $options
     */
    public static function priceList(TenantScenario $tenant, PriceListScope $scope, array $prices, array $options = []): PriceList
    {
        return $tenant->system(static function () use ($scope, $prices, $options): PriceList {
            $target = $options['target'] ?? null;
            $list = new PriceList;
            $list->forceFill([
                'code' => 'TAB-'.Str::upper(Str::random(8)),
                'name' => 'Tabela '.$scope->value,
                'scope' => $scope,
                'buyer_company_id' => $target instanceof BuyerCompany ? $target->id : null,
                'customer_group_id' => $target instanceof CustomerGroup ? $target->id : null,
                'priority' => $options['priority'] ?? 0,
                'valid_from' => $options['valid_from'] ?? now()->subDays(10)->toDateString(),
                'valid_until' => $options['valid_until'] ?? null,
                'status' => $options['status'] ?? PriceListStatus::Active,
            ])->save();

            foreach ($prices as $skuId => $cents) {
                $item = new PriceListItem;
                $item->forceFill(['price_list_id' => $list->id, 'sku_id' => $skuId, 'unit_price_cents' => $cents])->save();

                foreach ($options['tiers'][$skuId] ?? [] as [$minimum, $tierCents]) {
                    (new PriceTier)->forceFill([
                        'price_list_item_id' => $item->id,
                        'min_quantity' => $minimum,
                        'unit_price_cents' => $tierCents,
                    ])->save();
                }
            }

            return $list;
        });
    }

    public static function negotiated(TenantScenario $tenant, BuyerCompany $company, Sku $sku, int $cents, ?string $validUntil = null): NegotiatedPrice
    {
        return $tenant->system(static function () use ($company, $sku, $cents, $validUntil): NegotiatedPrice {
            $price = new NegotiatedPrice;
            $price->forceFill([
                'buyer_company_id' => $company->id,
                'sku_id' => $sku->id,
                'unit_price_cents' => $cents,
                'valid_from' => now()->subDays(5)->toDateString(),
                'valid_until' => $validUntil,
                'source' => 'manual',
                'status' => NegotiatedPriceStatus::Active,
            ])->save();

            return $price;
        });
    }

    /**
     * Custo e política comercial interna (nunca visíveis ao comprador).
     */
    public static function internalCosts(TenantScenario $tenant, Sku $sku, int $costCents, int $minimumPriceCents, int $minimumMarginBps): void
    {
        $tenant->system(static function () use ($tenant, $sku, $costCents, $minimumPriceCents, $minimumMarginBps): void {
            // sku_costs é append-only e só tem created_at: inserção direta.
            DB::table((new SkuCost)->getTable())->insert([
                'id' => Str::uuid7()->toString(),
                'tenant_id' => $tenant->organization->id,
                'sku_id' => $sku->id,
                'cost_cents' => $costCents,
                'effective_from' => now()->subDay(),
                'created_at' => now(),
            ]);
            (new SkuCommercialPolicy)->forceFill([
                'sku_id' => $sku->id,
                'min_unit_price_cents' => $minimumPriceCents,
                'min_margin_bps' => $minimumMarginBps,
            ])->save();
        });
    }

    /**
     * Mídia gravada no disco privado (use Storage::fake('private') no teste).
     */
    public static function media(
        TenantScenario $tenant,
        Product $product,
        MediaKind $kind,
        string $originalName,
        string $mimeType,
        string $contents,
        bool $visibleToBuyers = true,
        int $sortOrder = 0,
        bool $storeFile = true,
    ): ProductMedia {
        $path = 'catalogo/'.$tenant->organization->id.'/'.Str::uuid7()->toString().'.bin';

        if ($storeFile) {
            Storage::disk('private')->put($path, $contents);
        }

        return $tenant->system(static function () use ($product, $kind, $originalName, $mimeType, $contents, $visibleToBuyers, $sortOrder, $path): ProductMedia {
            $media = new ProductMedia;
            $media->forceFill([
                'product_id' => $product->id,
                'kind' => $kind,
                'disk' => 'private',
                'path' => $path,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size_bytes' => max(1, strlen($contents)),
                'sha256' => hash('sha256', $contents),
                'visible_to_buyers' => $visibleToBuyers,
                'sort_order' => $sortOrder,
            ])->save();

            return $media;
        });
    }

    /**
     * Retira uma permissão de um papel do tenant (ajuste feito pelo próprio tenant).
     */
    public static function revokePermission(TenantScenario $tenant, SystemRole $role, Permission $permission): void
    {
        $tenant->system(static function () use ($role, $permission): void {
            $stored = Role::query()->where('key', $role->value)->firstOrFail();
            $stored->forceFill([
                'permissions' => array_values(array_diff($stored->permissions, [$permission->value])),
            ])->save();
        });
    }

    public static function browse(TenantScenario $tenant, Membership $buyer, ?CatalogFilters $filters = null, int $page = 1): CatalogPage
    {
        return $tenant->actingAs($buyer, static fn (): CatalogPage => app(BuyerCatalog::class)->browse($filters ?? new CatalogFilters, $page));
    }

    /**
     * @return list<string>
     */
    public static function names(CatalogPage $page): array
    {
        return array_values(array_map(static fn (CatalogProduct $product): string => $product->name, $page->products->items()));
    }
}
