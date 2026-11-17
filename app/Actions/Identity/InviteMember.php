<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Data\Identity\InviteMemberData;
use App\Data\Identity\IssuedInvitation;
use App\Domain\Identity\Exceptions\AlreadyMember;
use App\Domain\Identity\Exceptions\RoleKindMismatch;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Branch;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Notifications\Identity\MemberInvitation;
use App\Services\Audit\AuditRecorder;
use App\Services\Platform\PlanLimits;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Emite convite de uso único para equipe ou empresa compradora.
 * Exige contexto de tenant ativo.
 */
final readonly class InviteMember
{
    public const int VALIDITY_DAYS = 7;

    public function __construct(
        private AuthorizesMemberManagement $authorization,
        private PlanLimits $limits,
        private AuditRecorder $audit,
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    public function handle(InviteMemberData $data): IssuedInvitation
    {
        $this->authorization->authorize($data->kind, $data->buyerCompanyId);

        return DB::transaction(function () use ($data): IssuedInvitation {
            $role = Role::query()->findOrFail($data->roleId);

            if ($role->kind !== $data->kind) {
                throw new RoleKindMismatch('O papel escolhido não pertence a este tipo de acesso.');
            }

            $this->authorization->authorizeRole($role);

            $buyerCompanyId = $data->kind === MembershipKind::Buyer
                ? BuyerCompany::query()->findOrFail((string) $data->buyerCompanyId)->id
                : null;
            $branchId = $data->kind === MembershipKind::Staff && $data->branchId !== null
                ? Branch::query()->findOrFail($data->branchId)->id
                : null;

            $email = mb_strtolower(trim($data->email));
            $this->assertNotMember($email, $data->kind, $buyerCompanyId);

            $now = $this->clock->now();

            // Reenvio: o convite pendente anterior para o mesmo contexto é revogado.
            Invitation::query()
                ->where('email', $email)
                ->where('kind', $data->kind->value)
                ->where('buyer_company_id', $buyerCompanyId)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            if ($data->kind === MembershipKind::Staff) {
                $this->limits->assertCanAddStaffMember();
            }

            $token = Str::random(64);
            $invitation = new Invitation;
            $invitation->forceFill([
                'email' => $email,
                'kind' => $data->kind,
                'buyer_company_id' => $buyerCompanyId,
                'role_id' => $role->id,
                'branch_id' => $branchId,
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => $now->addDays(self::VALIDITY_DAYS),
                'invited_by_user_id' => $this->context->require()->userId,
            ])->save();

            $this->audit->record('invitation.issued', $invitation, [
                'email' => $email,
                'kind' => $data->kind->value,
                'role' => $role->key,
                'buyer_company_id' => $buyerCompanyId,
            ]);

            $organization = Organization::query()->findOrFail($this->context->tenantId());
            $inviterId = $this->context->require()->userId;
            $inviter = $inviterId !== null ? User::query()->find($inviterId) : null;

            Notification::route('mail', $email)->notify(new MemberInvitation(
                organizationName: $organization->trade_name,
                inviterName: $inviter !== null ? $inviter->name : $organization->trade_name,
                roleName: $role->name,
                acceptUrl: route('invitations.show', ['organization' => $organization->id, 'token' => $token]),
                expiresAt: $invitation->expires_at,
            ));

            return new IssuedInvitation($invitation, $token);
        });
    }

    private function assertNotMember(string $email, MembershipKind $kind, ?string $buyerCompanyId): void
    {
        $userId = User::query()->where('email', $email)->value('id');

        if ($userId === null) {
            return;
        }

        $exists = Membership::query()
            ->where('user_id', $userId)
            ->where('kind', $kind->value)
            ->where('buyer_company_id', $buyerCompanyId)
            ->where('status', MembershipStatus::Active->value)
            ->exists();

        if ($exists) {
            throw new AlreadyMember('Este e-mail já possui acesso ativo neste contexto.');
        }
    }
}
