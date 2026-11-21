<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Produto do catálogo. Com histórico comercial, é arquivado (nunca excluído).
 * Situação, visibilidade e encomenda são alteradas por Actions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $category_id
 * @property string $base_unit_id
 * @property string $name
 * @property string $slug
 * @property string|null $brand
 * @property string|null $description
 * @property ProductStatus $status
 * @property ProductVisibility $visibility
 * @property bool $allow_backorder
 * @property int|null $backorder_lead_days
 * @property string|null $ncm
 * @property CarbonImmutable|null $archived_at
 * @property-read Category|null $category
 * @property-read UnitOfMeasure $baseUnit
 * @property-read Collection<int, Sku> $skus
 * @property-read Collection<int, ProductMedia> $media
 */
class Product extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'brand', 'description', 'ncm'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'draft', 'visibility' => 'public', 'allow_backorder' => false];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'visibility' => ProductVisibility::class,
            'allow_backorder' => 'boolean',
            'backorder_lead_days' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'base_unit_id');
    }

    /**
     * @return HasMany<Sku, $this>
     */
    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    /**
     * @return HasMany<ProductMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order');
    }

    public function isSellable(): bool
    {
        return $this->status === ProductStatus::Active;
    }
}
