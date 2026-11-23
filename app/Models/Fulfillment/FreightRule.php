<?php

declare(strict_types=1);

namespace App\Models\Fulfillment;

use App\Domain\Fulfillment\FreightRuleView;
use App\Domain\Shared\Money;
use App\Support\Database\Casts\AsMoney;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Regra de frete do distribuidor (RF-092).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property list<string> $states
 * @property Money $amount_cents
 * @property Money|null $free_from_cents
 * @property int $priority
 * @property bool $is_active
 */
class FreightRule extends Model
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
            'states' => 'array',
            'amount_cents' => AsMoney::class,
            'free_from_cents' => AsMoney::class,
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function view(): FreightRuleView
    {
        return new FreightRuleView($this->id, $this->name, array_values($this->states), $this->amount_cents, $this->free_from_cents, $this->priority);
    }
}
