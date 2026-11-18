<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Buyers;

use App\Actions\Identity\InviteMember;
use App\Actions\Identity\RevokeInvitation;
use App\Actions\Identity\RevokeMembership;
use App\Data\Identity\InviteMemberData;
use App\Domain\Buyers\Justification;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Identity\MembershipKind;
use App\Livewire\Staff\Buyers\Concerns\InteractsWithBuyerCompany;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Usuários da empresa compradora: associações de comprador, convites com
 * papéis de comprador e revogação com justificativa. Autorização e regras
 * ficam nas Actions de identidade (buyers.manage para a equipe).
 */
final class BuyerUsers extends Component
{
    use InteractsWithBuyerCompany;

    public string $inviteEmail = '';

    public string $inviteRoleId = '';

    public string $revokeReason = '';

    public ?string $message = null;

    public function mount(string $buyerId): void
    {
        $this->bindBuyer($buyerId);
    }

    public function invite(InviteMember $invite): void
    {
        $this->validate([
            'inviteEmail' => ['required', 'email:rfc', 'max:254'],
            'inviteRoleId' => ['required', 'uuid'],
        ], [], ['inviteEmail' => 'e-mail', 'inviteRoleId' => 'papel']);

        try {
            $invite->handle(new InviteMemberData(
                email: $this->inviteEmail,
                kind: MembershipKind::Buyer,
                roleId: $this->inviteRoleId,
                buyerCompanyId: $this->company()->id,
            ));
        } catch (BusinessRuleViolation $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->message = "Convite enviado para {$this->inviteEmail}.";
        $this->reset('inviteEmail', 'inviteRoleId');
        $this->resetErrorBag();
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function revoke(string $membershipId, RevokeMembership $revoke): void
    {
        $membership = Membership::query()
            ->where('kind', MembershipKind::Buyer->value)
            ->where('buyer_company_id', $this->buyerId)
            ->findOrFail(UntrustedId::uuid($membershipId, Membership::class));

        if (mb_strlen(trim($this->revokeReason)) > Justification::MAX_LENGTH) {
            $this->addError('revokeReason', 'A justificativa deve ter no máximo '.Justification::MAX_LENGTH.' caracteres.');

            return;
        }

        try {
            $revoke->handle($membership, $this->revokeReason);
        } catch (InvalidArgumentException) {
            $this->addError('revokeReason', 'Informe a justificativa (mínimo de '.Justification::MIN_LENGTH.' caracteres).');

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addError('revokeReason', $e->getMessage());

            return;
        }

        $this->message = 'Acesso revogado.';
        $this->reset('revokeReason');
        $this->resetErrorBag();
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function cancelInvitation(string $invitationId, RevokeInvitation $revoke): void
    {
        $invitation = Invitation::query()
            ->where('kind', MembershipKind::Buyer->value)
            ->where('buyer_company_id', $this->buyerId)
            ->findOrFail(UntrustedId::uuid($invitationId, Invitation::class));

        $revoke->handle($invitation);

        $this->message = 'Convite cancelado.';
        $this->dispatch(self::UPDATED_EVENT);
    }

    public function render(): View
    {
        return view('livewire.staff.buyers.buyer-users', [
            'members' => Membership::query()
                ->with(['user', 'role'])
                ->where('kind', MembershipKind::Buyer->value)
                ->where('buyer_company_id', $this->buyerId)
                ->orderBy('status')
                ->orderBy('created_at')
                ->get(),
            'invitations' => Invitation::query()
                ->with('role')
                ->where('kind', MembershipKind::Buyer->value)
                ->where('buyer_company_id', $this->buyerId)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', app(Clock::class)->now())
                ->orderBy('created_at')
                ->get(),
            'roles' => Role::query()
                ->where('kind', MembershipKind::Buyer->value)
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all(),
            'canManage' => $this->canManage(),
        ]);
    }
}
