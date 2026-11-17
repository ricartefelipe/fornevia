<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Domain\Identity\Exceptions\RoleKindMismatch;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

final readonly class ChangeMembershipRole
{
    public function __construct(
        private AuthorizesMemberManagement $authorization,
        private OwnershipGuard $ownership,
        private AuditRecorder $audit,
    ) {}

    public function handle(Membership $membership, string $roleId): Membership
    {
        $this->authorization->authorize($membership->kind, $membership->buyer_company_id);

        return DB::transaction(function () use ($membership, $roleId): Membership {
            // Ordem de locks: proprietários ativos (por id) e, depois, o alvo.
            $owners = $membership->kind === MembershipKind::Staff ? $this->ownership->lockActiveOwners() : collect();
            $locked = Membership::query()->with('role')->lockForUpdate()->findOrFail($membership->id);
            $role = Role::query()->findOrFail($roleId);

            if ($role->kind !== $locked->kind) {
                throw new RoleKindMismatch('O papel escolhido não pertence a este tipo de acesso.');
            }

            if ($role->id === $locked->role_id) {
                return $locked;
            }

            $this->authorization->authorizeRole($role);

            if ($locked->role->key === SystemRole::Owner->value) {
                $this->authorization->authorizeRole($locked->role);
                $this->ownership->assertAnotherActiveOwner($owners, $locked);
            }

            $previous = $locked->role->key;
            $locked->forceFill(['role_id' => $role->id])->save();

            $this->audit->record('membership.role_changed', $locked, ['from' => $previous, 'to' => $role->key]);

            return $locked->setRelation('role', $role);
        });
    }
}
