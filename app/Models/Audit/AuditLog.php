<?php

declare(strict_types=1);

namespace App\Models\Audit;

use App\Models\Identity\User;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de auditoria do tenant (append-only no banco).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $actor_user_id
 * @property string|null $actor_membership_id
 * @property string|null $actor_api_client_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed> $data
 * @property string|null $reason
 * @property string|null $ip
 * @property string|null $correlation_id
 * @property CarbonImmutable $created_at
 * @property-read User|null $actor
 */
class AuditLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
