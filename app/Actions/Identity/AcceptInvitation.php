<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Domain\Identity\Exceptions\ContextUnavailable;
use App\Domain\Identity\Exceptions\InvalidInvitation;
use App\Domain\Identity\Exceptions\InvitationEmailMismatch;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Platform\PlanLimits;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Aceite de convite. O tenant vem da URL, mas só é usado depois de
 * validado pelo token (hash) e pelo e-mail do usuário autenticado.
 * Repetir o aceite é idempotente.
 */
final readonly class AcceptInvitation
{
    public function __construct(
        private TenantDatabase $db,
        private PlanLimits $limits,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(string $organizationId, #[SensitiveParameter] string $plainToken, User $user): Membership
    {
        $organization = Str::isUuid($organizationId) ? Organization::query()->find($organizationId) : null;

        if ($organization === null) {
            throw new InvalidInvitation('Convite inválido.');
        }

        if (! $organization->allowsAccess()) {
            throw new ContextUnavailable('A organização não está disponível.');
        }

        return $this->db->run(
            ExecutionContext::system($organization->id, $user->id),
            fn (): Membership => $this->accept($plainToken, $user),
        );
    }

    private function accept(string $plainToken, User $user): Membership
    {
        $now = $this->clock->now();
        $invitation = Invitation::query()
            ->where('token_hash', Invitation::hashToken($plainToken))
            ->lockForUpdate()
            ->first();

        if ($invitation === null) {
            throw new InvalidInvitation('Convite inexistente.');
        }

        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            throw new InvitationEmailMismatch('Este convite foi enviado para outro e-mail. Entre com a conta correspondente.');
        }

        $existing = Membership::query()
            ->where('user_id', $user->id)
            ->where('kind', $invitation->kind->value)
            ->where('buyer_company_id', $invitation->buyer_company_id)
            ->lockForUpdate()
            ->first();

        if ($invitation->accepted_at !== null && $invitation->accepted_by_user_id === $user->id && $existing?->isActive() === true) {
            return $existing;
        }

        if (! $invitation->isPending($now)) {
            throw new InvalidInvitation('Convite expirado, revogado ou já utilizado.');
        }

        if ($invitation->kind === MembershipKind::Staff && $existing?->isActive() !== true) {
            $this->limits->assertStaffSeatForAcceptance();
        }

        $membership = $existing ?? new Membership;

        if (! $membership->exists || ! $membership->isActive()) {
            $membership->forceFill([
                'user_id' => $user->id,
                'kind' => $invitation->kind,
                'buyer_company_id' => $invitation->buyer_company_id,
                'role_id' => $invitation->role_id,
                'branch_id' => $invitation->branch_id,
                'status' => MembershipStatus::Active,
                'invited_by_user_id' => $invitation->invited_by_user_id,
                'revoked_at' => null,
                'revoked_by_user_id' => null,
                'revoke_reason' => null,
            ])->save();

            $this->audit->record('membership.granted', $membership, [
                'via' => 'invitation',
                'invitation_id' => $invitation->id,
                'kind' => $invitation->kind->value,
                'role_id' => $invitation->role_id,
            ]);
        }

        $invitation->forceFill(['accepted_at' => $now, 'accepted_by_user_id' => $user->id])->save();

        // O link recebido no e-mail comprova a posse do endereço.
        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['email_verified_at' => $now])->save();
        }

        return $membership;
    }
}
