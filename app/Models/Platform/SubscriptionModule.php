<?php

declare(strict_types=1);

namespace App\Models\Platform;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Módulo adicional contratado, com preço registrado no momento da ativação.
 *
 * @property string $id
 * @property string $subscription_id
 * @property string $module_code
 * @property int $price_cents
 * @property CarbonImmutable $activated_at
 * @property CarbonImmutable|null $deactivated_at
 */
class SubscriptionModule extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }
}
