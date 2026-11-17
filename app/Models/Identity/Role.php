<?php

declare(strict_types=1);

namespace App\Models\Identity;

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Papel de um contexto (equipe ou comprador) dentro do tenant.
 *
 * Permissões e limite de desconto não são atribuíveis em massa: são
 * alterados apenas por Action de gestão de papéis, com auditoria.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property MembershipKind $kind
 * @property list<string> $permissions
 * @property int $max_discount_bps
 * @property bool $is_system
 */
class Role extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MembershipKind::class,
            'permissions' => 'array',
            'max_discount_bps' => 'integer',
            'is_system' => 'boolean',
        ];
    }

    public function grants(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions, true);
    }

    public function isPrivileged(): bool
    {
        foreach ($this->permissions as $value) {
            if (Permission::tryFrom($value)?->isPrivileged() === true) {
                return true;
            }
        }

        return false;
    }
}
