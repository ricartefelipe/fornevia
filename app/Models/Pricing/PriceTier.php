<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Support\Database\Casts\AsMoney;
use App\Support\Database\Casts\AsQuantity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $price_list_item_id
 * @property Quantity $min_quantity
 * @property Money $unit_price_cents
 */
class PriceTier extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => AsQuantity::class,
            'unit_price_cents' => AsMoney::class,
        ];
    }
}
