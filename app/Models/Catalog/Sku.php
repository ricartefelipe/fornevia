<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\PurchaseRule;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\SkuStatus;
use App\Support\Database\Casts\AsDecimal;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SKU (variação vendável). Quantidades na unidade de venda; o fator de
 * conversão indica quantas unidades base cabem em uma unidade de venda.
 * Custos e política comercial ficam em tabelas restritas à equipe.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property string $sales_unit_id
 * @property string $code
 * @property string|null $gtin
 * @property string|null $variant_label
 * @property array<string, string> $attributes
 * @property BigDecimal $conversion_factor
 * @property string|null $package_label
 * @property Quantity $min_order_quantity
 * @property Quantity $order_multiple
 * @property int|null $weight_grams
 * @property SkuStatus $status
 * @property-read Product $product
 * @property-read UnitOfMeasure $salesUnit
 * @property-read SkuCommercialPolicy|null $commercialPolicy
 */
class Sku extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'gtin', 'variant_label', 'attributes', 'package_label', 'weight_grams'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'active', 'attributes' => '{}'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'conversion_factor' => AsDecimal::class.':6',
            'min_order_quantity' => AsQuantity::class,
            'order_multiple' => AsQuantity::class,
            'weight_grams' => 'integer',
            'status' => SkuStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function salesUnit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'sales_unit_id');
    }

    /**
     * Identificação da embalagem de venda (unidade e fator): preços digitados
     * em um formulário valem para a embalagem vista quando ele foi aberto.
     */
    public function packagingFingerprint(): string
    {
        return $this->sales_unit_id.'|'.(string) $this->conversion_factor->toScale(6);
    }

    /**
     * @return HasOne<SkuCommercialPolicy, $this>
     */
    public function commercialPolicy(): HasOne
    {
        return $this->hasOne(SkuCommercialPolicy::class);
    }

    /**
     * @return HasMany<SkuCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(SkuCost::class);
    }

    public function purchaseRule(): PurchaseRule
    {
        return new PurchaseRule($this->min_order_quantity, $this->order_multiple, $this->salesUnit->decimals);
    }

    public function toBaseQuantity(Quantity $salesQuantity): Quantity
    {
        return $salesQuantity->convertedBy($this->conversion_factor);
    }

    public function isSellable(): bool
    {
        return $this->status === SkuStatus::Active && $this->product->isSellable();
    }
}
