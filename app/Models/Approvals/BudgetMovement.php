<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimento de orçamento (reserva, liberação, consumo).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $cost_center_budget_id
 * @property string $order_id
 * @property \App\Enums\Approvals\BudgetMovementType $type
 * @property int $reserved_delta
 * @property int $consumed_delta
 * @property string|null $actor_user_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class BudgetMovement extends Model
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
            'type' => \App\Enums\Approvals\BudgetMovementType::class,
            'reserved_delta' => 'integer',
            'consumed_delta' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
