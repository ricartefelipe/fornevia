<?php

declare(strict_types=1);

namespace App\Models\Credit;

use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Exceção temporária de limite, com justificativa e responsável.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $credit_account_id
 * @property string $buyer_company_id
 * @property \App\Domain\Shared\Money $amount_cents
 * @property \Carbon\CarbonImmutable $valid_from
 * @property \Carbon\CarbonImmutable $valid_until
 * @property string $reason
 * @property string $granted_by_user_id
 * @property \Carbon\CarbonImmutable|null $revoked_at
 */
class CreditException extends Model
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
            'amount_cents' => AsMoney::class,
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
