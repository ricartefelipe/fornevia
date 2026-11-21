<?php

declare(strict_types=1);

namespace App\Models\Credit;

use App\Enums\Credit\CreditAccountStatus;
use App\Models\Buyers\BuyerCompany;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conta de crédito comercial da empresa compradora. Saldos (comprometido e a receber) mudam só por movimentos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property \App\Domain\Shared\Money $limit_cents
 * @property \App\Domain\Shared\Money $committed_cents
 * @property \App\Domain\Shared\Money $receivable_cents
 * @property CreditAccountStatus $status
 * @property string|null $blocked_reason
 * @property int $overdue_tolerance_days
 */
class CreditAccount extends Model
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
            'limit_cents' => AsMoney::class,
            'committed_cents' => AsMoney::class,
            'receivable_cents' => AsMoney::class,
            'status' => CreditAccountStatus::class,
            'overdue_tolerance_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BuyerCompany, $this>
     */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(BuyerCompany::class);
    }
}
