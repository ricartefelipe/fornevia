<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Endpoint que recebe os webhooks do distribuidor (RF-111): URL, segredo de
 * assinatura cifrado e eventos assinados.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $url
 * @property string $secret_encrypted
 * @property list<string> $events
 * @property bool $is_active
 * @property int $consecutive_failures
 * @property \Carbon\CarbonImmutable|null $disabled_at
 * @property string|null $disabled_reason
 * @property \Carbon\CarbonImmutable $created_at
 */
class WebhookEndpoint extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['consecutive_failures' => 0, 'is_active' => true];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
            'consecutive_failures' => 'integer',
            'disabled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function subscribes(string $event): bool
    {
        return in_array($event, $this->events, true);
    }
}
