<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Catalog\Support\CatalogText;
use App\Data\Catalog\ProductData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera os dados de um produto. Novos produtos começam como
 * rascunho; a situação muda apenas por ChangeProductStatus.
 *
 * - slug informado precisa estar livre no tenant; sem slug, é gerado a
 *   partir do nome (na criação) ou mantido (na edição);
 * - NCM opcional com 8 dígitos (pontos são ignorados);
 * - prazo de encomenda só quando o produto admite encomenda;
 * - a unidade base não muda depois que há SKUs (os fatores de conversão
 *   dependem dela).
 */
final readonly class SaveProduct
{
    public const int MAX_LEAD_DAYS = 365;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(?Product $product, ProductData $data): Product
    {
        $this->access->authorize(Permission::CatalogManage);

        $name = CatalogText::required($data->name, 'name', 200, 'Informe o nome do produto (até 200 caracteres).');
        $brand = CatalogText::optional($data->brand, 'brand', 80, 'A marca deve ter até 80 caracteres.');
        $description = CatalogText::multiline($data->description, 'description', 5000, 'A descrição deve ter até 5.000 caracteres.');
        $ncm = self::ncm($data->ncm);
        $requestedSlug = self::requestedSlug($data->slug);
        $leadDays = self::leadDays($data->allowBackorder, $data->backorderLeadDays);
        $slug = $requestedSlug ?? '';

        try {
            return DB::transaction(function () use ($product, $data, $name, $brand, $description, $ncm, $requestedSlug, $leadDays, &$slug): Product {
                if ($product !== null) {
                    $product = Product::query()->lockForUpdate()->findOrFail($product->id);

                    if ($product->status === ProductStatus::Archived) {
                        throw CatalogItemArchived::product();
                    }
                } else {
                    $product = new Product;
                }

                $creating = ! $product->exists;
                $baseUnit = UnitOfMeasure::query()->findOrFail(UntrustedId::uuid($data->baseUnitId, UnitOfMeasure::class));
                $category = $data->categoryId !== null
                    ? Category::query()->findOrFail(UntrustedId::uuid($data->categoryId, Category::class))
                    : null;

                if (! $creating && $product->base_unit_id !== $baseUnit->id
                    && Sku::query()->where('product_id', $product->id)->exists()) {
                    throw InvalidCatalogData::field('base_unit_id', 'A unidade base não pode ser alterada depois do cadastro de SKUs.');
                }

                if ($requestedSlug !== null) {
                    $taken = Product::query()
                        ->where('slug', $requestedSlug)
                        ->when(! $creating, static fn ($query) => $query->whereKeyNot($product->id))
                        ->exists();

                    if ($taken) {
                        throw self::slugInUse($requestedSlug);
                    }

                    $slug = $requestedSlug;
                } else {
                    $slug = $creating
                        ? CatalogText::uniqueSlug(Product::query(), $name, 'produto', 220)
                        : $product->slug;
                }

                $before = $creating ? null : self::snapshot($product);
                $product->forceFill([
                    'name' => $name,
                    'slug' => $slug,
                    'brand' => $brand,
                    'description' => $description,
                    'ncm' => $ncm,
                    'category_id' => $category?->id,
                    'base_unit_id' => $baseUnit->id,
                    'visibility' => $data->visibility,
                    'allow_backorder' => $data->allowBackorder,
                    'backorder_lead_days' => $leadDays,
                ]);

                if (! $creating && ! $product->isDirty()) {
                    return $product;
                }

                $product->save();

                $this->audit->record($creating ? 'product.created' : 'product.updated', $product, [
                    'before' => $before,
                    'after' => self::snapshot($product),
                ]);

                return $product;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::slugInUse($slug);
        }
    }

    private static function ncm(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = str_replace(['.', ' '], '', trim($value));

        if ($digits === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/D', $digits) !== 1) {
            throw InvalidCatalogData::field('ncm', 'O NCM deve ter 8 dígitos (ex.: 1905.90.90).');
        }

        return $digits;
    }

    private static function requestedSlug(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $slug = CatalogText::slug($value, 221);

        if ($slug === '' || mb_strlen($slug) > 220) {
            throw InvalidCatalogData::field('slug', 'O endereço (slug) deve ter de 1 a 220 letras, números ou hífens.');
        }

        return $slug;
    }

    private static function leadDays(bool $allowBackorder, ?int $leadDays): ?int
    {
        if ($leadDays === null) {
            return null;
        }

        if (! $allowBackorder) {
            throw InvalidCatalogData::field('backorder_lead_days', 'O prazo de encomenda só pode ser informado quando o produto admite encomenda.');
        }

        if ($leadDays < 1 || $leadDays > self::MAX_LEAD_DAYS) {
            throw InvalidCatalogData::field('backorder_lead_days', 'O prazo de encomenda deve estar entre 1 e '.self::MAX_LEAD_DAYS.' dias.');
        }

        return $leadDays;
    }

    private static function slugInUse(string $slug): CatalogIdentifierInUse
    {
        return CatalogIdentifierInUse::for('slug', $slug, 'Já existe um produto com este endereço (slug).');
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Product $product): array
    {
        return [
            'name' => $product->name,
            'slug' => $product->slug,
            'brand' => $product->brand,
            'ncm' => $product->ncm,
            'category_id' => $product->category_id,
            'base_unit_id' => $product->base_unit_id,
            'visibility' => $product->visibility->value,
            'allow_backorder' => $product->allow_backorder,
            'backorder_lead_days' => $product->backorder_lead_days,
        ];
    }
}
