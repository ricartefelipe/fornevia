<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Role;
use App\Support\Auth\Access;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Regra única de quem pode gerenciar acessos de cada tipo de contexto:
 * - equipe: members.manage;
 * - comprador: buyers.manage (equipe) ou portal.users na própria empresa.
 * Somente proprietários concedem ou retiram o papel de proprietário.
 */
final readonly class AuthorizesMemberManagement
{
    public function __construct(private Access $access) {}

    public function authorize(MembershipKind $kind, ?string $buyerCompanyId): void
    {
        if ($kind === MembershipKind::Staff) {
            $this->access->authorize(Permission::MembersManage);

            return;
        }

        if ($this->access->isStaff()) {
            $this->access->authorize(Permission::BuyersManage);

            return;
        }

        if ($this->access->isBuyer()
            && $this->access->can(Permission::PortalUsers)
            && $buyerCompanyId !== null
            && $buyerCompanyId === $this->access->buyerCompanyId()) {
            return;
        }

        throw new AuthorizationException('Sem permissão para gerenciar acessos desta empresa.');
    }

    public function authorizeRole(Role $role): void
    {
        if ($role->key === SystemRole::Owner->value && $this->access->membership()->role->key !== SystemRole::Owner->value) {
            throw new AuthorizationException('Somente proprietários concedem ou retiram o papel de proprietário.');
        }
    }
}
