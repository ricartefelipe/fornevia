<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Enums\Catalog\MediaKind;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imagem ou documento de produto em disco privado. Download apenas por
 * rota autorizada; o caminho físico nunca é exposto.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $product_id
 * @property MediaKind $kind
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property bool $visible_to_buyers
 * @property int $sort_order
 * @property string|null $uploaded_by_user_id
 * @property-read Product $product
 */
class ProductMedia extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /** @var list<string> */
    protected $hidden = ['disk', 'path'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'kind' => MediaKind::class,
            'size_bytes' => 'integer',
            'visible_to_buyers' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
