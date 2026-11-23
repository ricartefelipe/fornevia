<?php

declare(strict_types=1);

namespace App\Models\Fulfillment;

use App\Enums\Fulfillment\ShipmentEventKind;
use App\Enums\Fulfillment\ShipmentEventSource;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Evento de transporte da remessa (RN-091), somente inclusão.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $shipment_id
 * @property ShipmentEventSource $source
 * @property string $external_id
 * @property ShipmentEventKind $kind
 * @property string|null $description
 * @property \Carbon\CarbonImmutable $occurred_at
 * @property bool $applied
 * @property string|null $actor_user_id
 * @property \Carbon\CarbonImmutable $created_at
 */
class ShipmentEvent extends Model
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
            'source' => ShipmentEventSource::class,
            'kind' => ShipmentEventKind::class,
            'occurred_at' => 'immutable_datetime',
            'applied' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }
}
