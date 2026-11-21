<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Data\Catalog\Portal\CatalogFilters;
use App\Data\Catalog\Portal\CatalogMedia;
use App\Data\Catalog\Portal\CatalogPage;
use App\Data\Catalog\Portal\CatalogPrice;
use App\Data\Catalog\Portal\CatalogProduct;
use App\Data\Catalog\Portal\CatalogProductDetail;
use App\Data\Catalog\Portal\CatalogSku;
use App\Data\Catalog\Portal\CategoryOption;
use App\Data\Catalog\Portal\QuantityFeedback;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\SkuStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\Sku;
use App\Services\Pricing\PricingService;
use App\Support\Http\UntrustedId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Catálogo do portal para a empresa compradora do contexto atual.
 *
 * - Visibilidade: RLS e, além dela, filtro explícito (BuyerCatalogScope).
 * - Relações carregadas antecipadamente (SKUs ativos, unidade de venda e
 *   mídias visíveis): o modo estrito do Eloquent recusa lazy loading.
 * - Preços: uma única chamada ao PricingService por página, com todos os
 *   SKUs exibidos, na quantidade mínima de compra (ou na quantidade
 *   conferida, na página do produto).
 * - Retorna DTOs sem custo, margem ou política comercial.
 */
final readonly class BuyerCatalog
{
    public const int PER_PAGE = 24;

    public function __construct(
        private BuyerCatalogScope $scope,
        private PricingService $pricing,
        private PurchaseQuantityAdvisor $advisor,
    ) {}

    /**
     * Listagem paginada com busca (nome, marca, código do SKU) e categoria
     * (incluindo subcategorias).
     *
     * @throws AuthorizationException
     */
    public function browse(CatalogFilters $filters, int $page = 1): CatalogPage
    {
        $company = $this->scope->company();
        [$categories, $children] = $this->categoryTree($company);

        $available = array_map(static fn (CategoryOption $option): string => $option->id, $categories);

        if ($filters->categoryId !== null && ! in_array($filters->categoryId, $available, true)) {
            $filters = $filters->withoutCategory();
        }

        $query = $this->scope->visibleProducts(Product::query(), $company)
            ->select('products.*')
            ->with($this->listingRelations());

        if ($filters->search !== '') {
            $this->applySearch($query, $filters->search);
        }

        if ($filters->categoryId !== null) {
            $query->whereIn('products.category_id', $this->withDescendants($filters->categoryId, $children));
        }

        $paginator = $query->orderBy('products.name')
            ->orderBy('products.id')
            ->paginate(self::PER_PAGE, ['products.*'], 'page', max(1, $page));

        /** @var list<Product> $products */
        $products = array_values($paginator->items());
        $quantities = [];

        foreach ($products as $product) {
            foreach ($product->skus as $sku) {
                $quantities[$sku->id] = $this->advisor->reference($sku->purchaseRule());
            }
        }

        $prices = $this->prices($company, $quantities);

        $items = array_map(fn (Product $product): CatalogProduct => new CatalogProduct(
            id: $product->id,
            name: $product->name,
            brand: $product->brand,
            category: $product->category?->name,
            thumbnail: ($image = $product->media->first()) !== null ? $this->media($image) : null,
            skus: array_values($product->skus->map(
                fn (Sku $sku): CatalogSku => $this->sku($sku, $quantities[$sku->id], $prices[$sku->id] ?? null),
            )->all()),
        ), $products);

        return new CatalogPage(
            products: new LengthAwarePaginator($items, $paginator->total(), $paginator->perPage(), $paginator->currentPage(), [
                'path' => Paginator::resolveCurrentPath(),
                'pageName' => 'page',
            ]),
            categories: $categories,
            filters: $filters,
            companyName: $company->trade_name,
            companyCanPurchase: $company->status->canPurchase(),
            companyStatusLabel: $company->status->label(),
        );
    }

    /**
     * Produto visível ao comprador, com a quantidade digitada por SKU
     * conferida contra a regra de compra e o preço resolvido para a
     * quantidade válida (a informada ou a sugestão mais próxima).
     *
     * @param  array<array-key, mixed>  $requested  quantidade por SKU (entrada não confiável)
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException produto inexistente, de outro tenant ou não visível
     */
    public function product(string $productId, array $requested = []): CatalogProductDetail
    {
        $company = $this->scope->company();

        $product = $this->scope->visibleProducts(Product::query(), $company)
            ->select('products.*')
            ->with($this->detailRelations())
            ->whereKey(UntrustedId::uuid($productId, Product::class))
            ->firstOrFail();

        $quantities = [];
        $feedback = [];

        foreach ($product->skus as $sku) {
            $rule = $sku->purchaseRule();
            $reference = $this->advisor->reference($rule);
            $input = $requested[$sku->id] ?? null;

            if (! is_string($input) || trim($input) === '') {
                $quantities[$sku->id] = $reference;

                continue;
            }

            $checked = $this->advisor->evaluate($rule, $input, $sku->salesUnit->code);
            $feedback[$sku->id] = $checked;
            $quantities[$sku->id] = $this->quantityToPrice($checked, $reference);
        }

        $prices = $this->prices($company, $quantities);
        $images = [];
        $documents = [];

        foreach ($product->media as $media) {
            if ($media->kind === MediaKind::Image) {
                $images[] = $this->media($media);
            } else {
                $documents[] = $this->media($media);
            }
        }

        return new CatalogProductDetail(
            id: $product->id,
            name: $product->name,
            brand: $product->brand,
            description: $product->description,
            category: $product->category?->name,
            skus: array_values($product->skus->map(fn (Sku $sku): CatalogSku => $this->sku(
                $sku,
                $quantities[$sku->id],
                $prices[$sku->id] ?? null,
                $feedback[$sku->id] ?? null,
            ))->all()),
            images: $images,
            documents: $documents,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function listingRelations(): array
    {
        return [
            'category',
            'skus' => static fn (HasMany $skus): HasMany => $skus
                ->where('skus.status', SkuStatus::Active->value)
                ->orderBy('skus.code')
                ->orderBy('skus.id'),
            'skus.salesUnit',
            'media' => static fn (HasMany $media): HasMany => $media
                ->where('product_media.kind', MediaKind::Image->value)
                ->where('product_media.visible_to_buyers', true)
                ->orderBy('product_media.id')
                ->limit(1),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function detailRelations(): array
    {
        return [
            'category',
            'skus' => static fn (HasMany $skus): HasMany => $skus
                ->where('skus.status', SkuStatus::Active->value)
                ->orderBy('skus.code')
                ->orderBy('skus.id'),
            'skus.salesUnit',
            'media' => static fn (HasMany $media): HasMany => $media
                ->where('product_media.visible_to_buyers', true)
                ->orderBy('product_media.id'),
        ];
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function applySearch(Builder $query, string $term): void
    {
        $pattern = '%'.addcslashes($term, '\\%_').'%';

        $query->where(static function (Builder $match) use ($pattern): void {
            $match->where('products.name', 'ilike', $pattern)
                ->orWhere('products.brand', 'ilike', $pattern)
                ->orWhereExists(static function (QueryBuilder $sku) use ($pattern): void {
                    $sku->selectRaw('1')
                        ->from('skus as matched')
                        ->whereColumn('matched.product_id', 'products.id')
                        ->whereColumn('matched.tenant_id', 'products.tenant_id')
                        ->where('matched.status', SkuStatus::Active->value)
                        ->where('matched.code', 'ilike', $pattern);
                });
        });
    }

    /**
     * Categorias com produtos visíveis (e seus ancestrais), em ordem de
     * exibição, e o mapa de filhos de cada categoria.
     *
     * @return array{0: list<CategoryOption>, 1: array<string, list<string>>}
     */
    private function categoryTree(BuyerCompany $company): array
    {
        /** @var list<string> $used */
        $used = $this->scope->visibleProducts(Product::query(), $company)
            ->whereNotNull('products.category_id')
            ->distinct()
            ->pluck('products.category_id')
            ->all();

        if ($used === []) {
            return [[], []];
        }

        $categories = Category::query()->orderBy('sort_order')->orderBy('name')->orderBy('id')->get()->keyBy('id');
        $children = [];
        $roots = [];

        foreach ($categories as $category) {
            if ($category->parent_id !== null && $categories->has($category->parent_id)) {
                $children[$category->parent_id][] = $category->id;
            } else {
                $roots[] = $category->id;
            }
        }

        $included = [];

        foreach ($used as $id) {
            $guard = 0;

            while ($id !== null && $categories->has($id) && ! isset($included[$id]) && $guard++ < 100) {
                $included[$id] = true;
                $id = $categories->get($id)?->parent_id;
            }
        }

        $options = [];
        $visited = [];
        $stack = array_map(static fn (string $id): array => [$id, 0], array_reverse($roots));

        while ($stack !== []) {
            [$id, $depth] = array_pop($stack);

            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $category = $categories->get($id);

            if ($category === null || ! isset($included[$id])) {
                continue;
            }

            $options[] = new CategoryOption($category->id, $category->name, $depth);

            foreach (array_reverse($children[$id] ?? []) as $child) {
                $stack[] = [$child, $depth + 1];
            }
        }

        return [$options, $children];
    }

    /**
     * @param  array<string, list<string>>  $children
     * @return list<string>
     */
    private function withDescendants(string $categoryId, array $children): array
    {
        $ids = [];
        $pending = [$categoryId];

        while ($pending !== []) {
            $id = array_pop($pending);

            if (isset($ids[$id])) {
                continue;
            }

            $ids[$id] = true;

            foreach ($children[$id] ?? [] as $child) {
                $pending[] = $child;
            }
        }

        return array_keys($ids);
    }

    /**
     * Quantidade usada no preço: a informada, se válida; senão a sugestão
     * mais próxima; sem sugestão, a quantidade mínima de compra.
     */
    private function quantityToPrice(QuantityFeedback $feedback, Quantity $reference): Quantity
    {
        if ($feedback->valid && $feedback->requested !== null) {
            return $feedback->requested;
        }

        return $feedback->suggestion ?? $reference;
    }

    /**
     * @param  array<string, Quantity>  $quantities
     * @return array<string, ResolvedPrice|null>
     */
    private function prices(BuyerCompany $company, array $quantities): array
    {
        return $quantities === [] ? [] : $this->pricing->resolve($company, $quantities);
    }

    private function sku(Sku $sku, Quantity $quantity, ?ResolvedPrice $resolved, ?QuantityFeedback $feedback = null): CatalogSku
    {
        $unit = $sku->salesUnit;

        return new CatalogSku(
            id: $sku->id,
            code: $sku->code,
            variant: $sku->variant_label,
            package: $sku->package_label,
            unitCode: $unit->code,
            unitName: $unit->name,
            unitDecimals: $unit->decimals,
            minimum: $sku->min_order_quantity,
            multiple: $sku->order_multiple,
            quantity: $quantity,
            price: $resolved !== null ? CatalogPrice::fromResolved($resolved, $quantity) : null,
            attributes: self::attributes($sku),
            feedback: $feedback,
        );
    }

    private function media(ProductMedia $media): CatalogMedia
    {
        return new CatalogMedia(
            id: $media->id,
            kind: $media->kind,
            name: MediaFileName::sanitize($media->original_name),
            mimeType: $media->mime_type,
            sizeBytes: $media->size_bytes,
        );
    }

    /**
     * Atributos da variação (ex.: cor, voltagem) como texto.
     *
     * @return array<string, string>
     */
    private static function attributes(Sku $sku): array
    {
        $raw = $sku->getAttribute('attributes');
        $attributes = [];

        if (! is_array($raw)) {
            return [];
        }

        foreach ($raw as $name => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                $attributes[(string) $name] = (string) $value;
            }
        }

        return $attributes;
    }
}
