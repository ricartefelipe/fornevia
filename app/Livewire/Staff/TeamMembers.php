<?php

declare(strict_types=1);

namespace App\Livewire\Staff;

use App\Actions\Identity\ChangeMembershipRole;
use App\Actions\Identity\InviteMember;
use App\Actions\Identity\RevokeInvitation;
use App\Actions\Identity\RevokeMembership;
use App\Data\Identity\InviteMemberData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\MembershipKind;
use App\Models\Identity\Branch;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Equipe do distribuidor. Identificadores recebidos nas ações são entrada
 * não confiável: os registros são recarregados no escopo do tenant e a
 * autorização é refeita pelas Actions.
 */
#[Layout('layouts.app')]
#[Title('Equipe e permissões')]
final class TeamMembers extends Component
{
    public string $inviteEmail = '';

    public string $inviteRoleId = '';

    public string $inviteBranchId = '';

    public string $revokeReason = '';

    public ?string $message = null;

    /** @var array<string, string> papel escolhido por associação (entrada não confiável) */
    public array $roleSelections = [];

    public function mount(): void
    {
        $this->syncRoleSelections();
    }

    public function invite(InviteMember $invite): void
    {
        $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:254'],
            'inviteRoleId' => ['required', 'uuid'],
            'inviteBranchId' => ['nullable', 'uuid'],
        ], [], ['inviteEmail' => 'e-mail', 'inviteRoleId' => 'papel', 'inviteBranchId' => 'filial']);

        try {
            $invite->handle(new InviteMemberData(
                email: $this->inviteEmail,
                kind: MembershipKind::Staff,
                roleId: $this->inviteRoleId,
                branchId: $this->inviteBranchId !== '' ? $this->inviteBranchId : null,
            ));
        } catch (BusinessRuleViolation $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->message = "Convite enviado para {$this->inviteEmail}.";
        $this->reset('inviteEmail', 'inviteRoleId', 'inviteBranchId');
    }

    public function revoke(string $membershipId, RevokeMembership $revoke): void
    {
        $membership = $this->staffMembership($membershipId);

        try {
            $revoke->handle($membership, $this->revokeReason);
        } catch (InvalidArgumentException) {
            $this->addError('revokeReason', 'Informe a justificativa (mínimo de 5 caracteres).');

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addError('revokeReason', $e->getMessage());

            return;
        }

        $this->message = 'Acesso revogado.';
        $this->reset('revokeReason');
    }

    public function changeRole(string $membershipId, ChangeMembershipRole $change): void
    {
        $roleId = (string) ($this->roleSelections[$membershipId] ?? '');

        try {
            $change->handle($this->staffMembership($membershipId), $roleId);
        } catch (BusinessRuleViolation $e) {
            $this->message = $e->getMessage();
            $this->syncRoleSelections();

            return;
        }

        $this->message = 'Papel atualizado.';
    }

    public function cancelInvitation(string $invitationId, RevokeInvitation $revoke): void
    {
        $invitation = Invitation::query()->where('kind', MembershipKind::Staff->value)->findOrFail($invitationId);
        $revoke->handle($invitation);
        $this->message = 'Convite cancelado.';
    }

    public function render(): View
    {
        return view('livewire.staff.team-members', [
            'members' => Membership::query()
                ->with(['user', 'role', 'branch'])
                ->where('kind', MembershipKind::Staff->value)
                ->orderBy('status')
                ->orderBy('created_at')
                ->get(),
            'invitations' => Invitation::query()
                ->with('role')
                ->where('kind', MembershipKind::Staff->value)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderBy('created_at')
                ->get(),
            'roles' => Role::query()->where('kind', MembershipKind::Staff->value)->orderBy('name')->get(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    private function staffMembership(string $membershipId): Membership
    {
        return Membership::query()
            ->where('kind', MembershipKind::Staff->value)
            ->findOrFail($membershipId);
    }

    private function syncRoleSelections(): void
    {
        $this->roleSelections = Membership::query()
            ->where('kind', MembershipKind::Staff->value)
            ->pluck('role_id', 'id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }
}
