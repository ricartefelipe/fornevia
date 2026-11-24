<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * Token do Sanctum de um cliente de API, com o tenant do cliente (ADR-029).
 *
 * @property int $id
 * @property string $tenant_id
 * @property string $tokenable_id
 * @property string $name
 * @property list<string>|null $abilities
 * @property \Carbon\CarbonImmutable|null $last_used_at
 * @property \Carbon\CarbonImmutable|null $expires_at
 */
class ApiToken extends PersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'json',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
