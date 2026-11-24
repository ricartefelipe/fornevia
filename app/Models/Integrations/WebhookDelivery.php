<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entrega de um evento a um endpoint: id estável (o mesmo nas novas
 * tentativas), tentativas, resposta e próxima tentativa.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $webhook_endpoint_id
 * @property string $outbox_message_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $last_error
 * @property \Carbon\CarbonImmutable|null $next_attempt_at
 * @property \Carbon\CarbonImmutable|null $delivered_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['attempts' => 0, 'status' => 'pending'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'response_status' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<WebhookEndpoint, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
