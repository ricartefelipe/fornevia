<?php

declare(strict_types=1);

namespace Tests\Integration\Identity\Invitations;

use App\Actions\Identity\AcceptInvitation;
use App\Actions\Identity\InviteMember;
use App\Actions\Identity\RevokeInvitation;
use App\Data\Identity\InviteMemberData;
use App\Data\Identity\IssuedInvitation;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\Support\Builders\TenantScenario;

/**
 * Apoio comum aos testes de convites: emissão e aceite pelos caminhos
 * reais da aplicação (Actions), relógio controlado e leituras no
 * contexto de sistema do tenant.
 */
final class InvitationFixtures
{
    /**
     * Relógio congelado no segundo atual (o banco grava timestamps sem
     * frações de segundo), registrado no container.
     */
    public static function freezeClock(): FrozenClock
    {
        $clock = new FrozenClock(CarbonImmutable::now('UTC')->startOfSecond());
        app()->instance(Clock::class, $clock);

        return $clock;
    }

    /**
     * Emite um convite como a associação informada. O tipo de acesso
     * acompanha o papel, salvo quando informado explicitamente.
     */
    public static function issue(
        TenantScenario $tenant,
        Membership $actor,
        string $email,
        SystemRole $role,
        ?BuyerCompany $company = null,
        ?string $branchId = null,
        ?MembershipKind $kind = null,
    ): IssuedInvitation {
        $roleId = $tenant->role($role)->id;

        return $tenant->actingAs($actor, static fn (): IssuedInvitation => app(InviteMember::class)->handle(new InviteMemberData(
            email: $email,
            kind: $kind ?? $role->kind(),
            roleId: $roleId,
            buyerCompanyId: $company?->id,
            branchId: $branchId,
        )));
    }

    public static function accept(TenantScenario|string $tenant, string $token, User $user): Membership
    {
        $organizationId = $tenant instanceof TenantScenario ? $tenant->organization->id : $tenant;

        return app(AcceptInvitation::class)->handle($organizationId, $token, $user);
    }

    /**
     * Parâmetros das rotas /convites/{organization}/{token}.
     *
     * @return array{organization: string, token: string}
     */
    public static function routeParameters(TenantScenario|string $tenant, string $token): array
    {
        return ['organization' => $tenant instanceof TenantScenario ? $tenant->organization->id : $tenant, 'token' => $token];
    }

    public static function revoke(TenantScenario $tenant, Membership $actor, Invitation $invitation): void
    {
        $tenant->actingAs($actor, static fn () => app(RevokeInvitation::class)->handle($invitation));
    }

    public static function invitation(TenantScenario $tenant, string $id): Invitation
    {
        return $tenant->system(static fn (): Invitation => Invitation::query()->findOrFail($id));
    }

    public static function pendingCount(TenantScenario $tenant, MembershipKind $kind = MembershipKind::Staff): int
    {
        return $tenant->system(static fn (): int => Invitation::query()
            ->where('kind', $kind->value)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->count());
    }

    /**
     * @return list<Membership>
     */
    public static function membershipsOf(TenantScenario $tenant, User $user): array
    {
        return $tenant->system(static fn (): array => Membership::query()
            ->where('user_id', $user->id)
            ->get()
            ->all());
    }

    /**
     * @return list<AuditLog>
     */
    public static function audits(TenantScenario $tenant, string $action): array
    {
        return $tenant->system(static fn (): array => AuditLog::query()
            ->where('action', $action)
            ->orderBy('created_at')
            ->get()
            ->all());
    }
}
