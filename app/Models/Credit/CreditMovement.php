<?php

declare(strict_types=1);

namespace App\Models\Credit;

use App\Enums\Credit\CreditMovementType;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Movimento de crédito (append-only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $credit_account_id
 * @property string $buyer_company_id
 * @property CreditMovementType $type
 * @property \App\Domain\Shared\Money $committed_delta
 * @property \App\Domain\Shared\Money $receivable_delta
 * @property string $reference_type
 * @property string $reference_id
 * @property string|null $reason
 * @property string|null $actor_user_id
 */
class CreditMovement extends Model
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
            'type' => CreditMovementType::class,
            'committed_delta' => AsMoney::class,
            'receivable_delta' => AsMoney::class,
        ];
    }
}
