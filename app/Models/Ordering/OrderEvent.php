<?php

declare(strict_types=1);

namespace App\Models\Ordering;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Histórico do pedido (append-only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $order_id
 * @property string $buyer_company_id
 * @property string $type
 * @property array<string, mixed> $data
 * @property string|null $actor_user_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class OrderEvent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
