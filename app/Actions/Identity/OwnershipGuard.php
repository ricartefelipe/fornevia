<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Domain\Identity\Exceptions\LastOwnerRequired;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/**
 * Garante ao menos um proprietário ativo.
 *
 * Ordem de locks (ADR-006): primeiro todas as associações de proprietário
 * ativas, em ordem de id; depois a associação alvo. Com essa ordem única,
 * duas revogações concorrentes não entram em deadlock e a segunda enxerga
 * o efeito da primeira.
 */
final class OwnershipGuard
{
    /**
     * @return Collection<int, string> ids das associações de proprietário ativas, bloqueadas
     */
    public function lockActiveOwners(): Collection
    {
        $ownerRoleId = Role::query()->where('key', SystemRole::Owner->value)->value('id');

        /** @var Collection<int, string> $ids */
        $ids = Membership::query()
            ->where('kind', MembershipKind::Staff->value)
            ->where('role_id', $ownerRoleId)
            ->where('status', MembershipStatus::Active->value)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        return $ids;
    }

    /**
     * Confirma, já sob lock, que o ator continua proprietário ativo: uma
     * revogação concorrente do próprio ator não pode ser ignorada.
     *
     * @param  Collection<int, string>  $lockedOwnerIds
     */
    public function assertActorIsActiveOwner(Collection $lockedOwnerIds, ?string $actorMembershipId): void
    {
        if ($actorMembershipId === null || ! $lockedOwnerIds->contains($actorMembershipId)) {
            throw new AuthorizationException('Somente proprietários ativos concedem ou retiram o papel de proprietário.');
        }
    }

    /**
     * @param  Collection<int, string>  $lockedOwnerIds
     */
    public function assertAnotherActiveOwner(Collection $lockedOwnerIds, Membership $leaving): void
    {
        if ($lockedOwnerIds->reject(static fn (string $id): bool => $id === $leaving->id)->isEmpty()) {
            throw new LastOwnerRequired('A organização precisa manter ao menos um proprietário ativo.');
        }
    }
}
