<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Support\Tenancy\BelongsToTenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * Cliente de API do distribuidor (RF-110): nome, permissões concedidas e
 * tokens do Sanctum com abilities e prazo, vinculados ao tenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property list<string> $permissions
 * @property string|null $created_by_user_id
 * @property \Carbon\CarbonImmutable|null $last_used_at
 * @property \Carbon\CarbonImmutable|null $revoked_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ApiToken> $tokens
 */
class ApiClient extends Model
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
            'permissions' => 'array',
            'last_used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphMany<ApiToken, $this>
     */
    public function tokens(): MorphMany
    {
        return $this->morphMany(ApiToken::class, 'tokenable');
    }

    /**
     * Emite um token: devolve o texto simples ("id|segredo"), mostrado uma
     * única vez; só o SHA-256 do segredo é gravado.
     *
     * @param  list<string>  $abilities
     * @return array{0: ApiToken, 1: string}
     */
    public function issueToken(string $name, array $abilities, ?DateTimeInterface $expiresAt): array
    {
        $secret = 'fvk_'.Str::random(48);
        $token = new ApiToken;
        $token->forceFill([
            'tenant_id' => $this->tenant_id,
            'tokenable_type' => $this->getMorphClass(),
            'tokenable_id' => $this->id,
            'name' => $name,
            'token' => hash('sha256', $secret),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ])->save();

        return [$token, $token->getKey().'|'.$secret];
    }
}
