<?php

declare(strict_types=1);

namespace App\Models\Platform;

use App\Enums\Platform\SubscriptionStatus;
use App\Models\Identity\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Assinatura do distribuidor no Fornevia. Não participa das vendas do
 * distribuidor aos seus clientes.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $plan_id
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $current_period_start
 * @property CarbonImmutable|null $current_period_end
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Plan $plan
 * @property-read Organization $organization
 * @property-read Collection<int, SubscriptionModule> $modules
 */
class Subscription extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'immutable_datetime',
            'current_period_start' => 'immutable_datetime',
            'current_period_end' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<SubscriptionModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(SubscriptionModule::class);
    }
}
