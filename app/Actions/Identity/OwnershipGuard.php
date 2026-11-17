<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Domain\Identity\Exceptions\LastOwnerRequired;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;

/**
 * Garante ao menos um proprietário ativo. Bloqueia todas as associações de
 * proprietário (em ordem de id) antes de contar, evitando que duas
 * revogações concorrentes removam os dois últimos proprietários.
 */
final class OwnershipGuard
{
    public function assertAnotherActiveOwner(Membership $leaving): void
    {
        $ownerRoleId = Role::query()->where('key', SystemRole::Owner->value)->value('id');

        $owners = Membership::query()
            ->where('kind', MembershipKind::Staff->value)
            ->where('role_id', $ownerRoleId)
            ->where('status', MembershipStatus::Active->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($owners->reject(static fn (string $id): bool => $id === $leaving->id)->isEmpty()) {
            throw new LastOwnerRequired('A organização precisa manter ao menos um proprietário ativo.');
        }
    }
}
