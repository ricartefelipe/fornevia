<?php

declare(strict_types=1);

namespace App\Models\Approvals;

use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Orçamento mensal de um centro de custo (projeção de budget_movements).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $cost_center_id
 * @property \Carbon\CarbonImmutable $period_start
 * @property \App\Domain\Shared\Money $amount_cents
 * @property \App\Domain\Shared\Money $reserved_cents
 * @property \App\Domain\Shared\Money $consumed_cents
 */
class CostCenterBudget extends Model
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
            'period_start' => 'immutable_date',
            'amount_cents' => AsMoney::class,
            'reserved_cents' => AsMoney::class,
            'consumed_cents' => AsMoney::class,
        ];
    }

    public function position(): \App\Domain\Approvals\BudgetPosition
    {
        return new \App\Domain\Approvals\BudgetPosition($this->amount_cents, $this->reserved_cents, $this->consumed_cents);
    }
}
