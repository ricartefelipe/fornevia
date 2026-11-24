<?php

declare(strict_types=1);

namespace App\Models\Notifications;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Notificação no sistema de uma associação (ADR-026). Criada no contexto de
 * sistema; a pessoa só vê as próprias e só marca a leitura.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string $user_id
 * @property string|null $outbox_message_id
 * @property string $kind
 * @property string $title
 * @property string $body
 * @property string $url
 * @property \Carbon\CarbonImmutable|null $read_at
 * @property \Carbon\CarbonImmutable $created_at
 */
class UserNotification extends Model
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
            'read_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
