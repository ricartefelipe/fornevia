<?php

declare(strict_types=1);

use App\Actions\Identity\RevokeInvitation;
use App\Actions\Identity\RevokeMembership;
use App\Domain\Identity\Exceptions\AlreadyMember;
use App\Domain\Identity\Exceptions\InvalidInvitation;
use App\Domain\Identity\Exceptions\RoleKindMismatch;
use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Branch;
use App\Models\Identity\Invitation;
use App\Models\Identity\User;
use App\Notifications\Identity\MemberInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Integration\Identity\Invitations\InvitationFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Notification::fake();
    $this->clock = Fixtures::freezeClock();
});

it('emite convite de equipe guardando apenas o hash do token, notificando o e-mail e auditando', function (): void {
    $tenant = TenantScenario::create();
    $branch = $tenant->system(fn (): Branch => Branch::factory()->create(['name' => 'Filial Campinas']));

    $issued = Fixtures::issue($tenant, $tenant->ownerMembership, '  Marina.Alves@Example.com ', SystemRole::SalesRep, branchId: $branch->id);
    $invitation = Fixtures::invitation($tenant, $issued->invitation->id);

    expect($issued->plainToken)->toHaveLength(64)
        ->and($invitation->email)->toBe('marina.alves@example.com')
        ->and($invitation->kind)->toBe(MembershipKind::Staff)
        ->and($invitation->role_id)->toBe($tenant->role(SystemRole::SalesRep)->id)
        ->and($invitation->branch_id)->toBe($branch->id)
        ->and($invitation->buyer_company_id)->toBeNull()
        ->and($invitation->invited_by_user_id)->toBe($tenant->owner->id)
        ->and($invitation->expires_at->equalTo($this->clock->now()->addDays(7)))->toBeTrue()
        ->and($invitation->toArray())->not->toHaveKey('token_hash');

    // No banco existe somente o SHA-256; o token em claro não aparece em coluna alguma.
    $row = (array) $tenant->system(fn (): object => DB::table('invitations')->where('id', $invitation->id)->first());
    $leaks = array_filter($row, static fn (mixed $value): bool => is_string($value) && str_contains($value, $issued->plainToken));

    expect($row['token_hash'])->toBe(hash('sha256', $issued->plainToken))
        ->and($leaks)->toBe([]);

    $expectedUrl = route('invitations.show', ['organization' => $tenant->organization->id, 'token' => $issued->plainToken]);

    Notification::assertSentOnDemandTimes(MemberInvitation::class, 1);
    Notification::assertSentOnDemand(
        MemberInvitation::class,
        function (MemberInvitation $notification, array $channels, AnonymousNotifiable $notifiable) use ($tenant, $expectedUrl, $invitation): bool {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes === ['mail' => 'marina.alves@example.com']
                && $channels === ['mail']
                && $notification->afterCommit === true
                && $notification->acceptUrl === $expectedUrl
                && $mail->actionUrl === $expectedUrl
                && $notification->organizationName === $tenant->organization->trade_name
                && $notification->inviterName === $tenant->owner->name
                && $notification->roleName === SystemRole::SalesRep->label()
                && $notification->expiresAt->equalTo($invitation->expires_at);
        },
    );

    $audits = Fixtures::audits($tenant, 'invitation.issued');

    expect($audits)->toHaveCount(1)
        ->and($audits[0]->subject_type)->toBe('invitation')
        ->and($audits[0]->subject_id)->toBe($invitation->id)
        ->and($audits[0]->actor_user_id)->toBe($tenant->owner->id)
        ->and($audits[0]->actor_membership_id)->toBe($tenant->ownerMembership->id)
        ->and($audits[0]->data)->toEqual([
            'email' => 'marina.alves@example.com',
            'kind' => 'staff',
            'role' => 'sales_rep',
            'buyer_company_id' => null,
        ])
        ->and(json_encode($audits[0]->data, JSON_THROW_ON_ERROR))->not->toContain($issued->plainToken);
});

it('exige a permissão de gestão de equipe para convidar para a equipe', function (SystemRole $actorRole): void {
    $tenant = TenantScenario::create();
    $actor = $tenant->addStaff($actorRole);

    expect(fn () => Fixtures::issue($tenant, $actor, 'paulo.mendes@example.com', SystemRole::Viewer))
        ->toThrow(AuthorizationException::class);

    expect(Fixtures::pendingCount($tenant))->toBe(0);
    Notification::assertNothingSent();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'representante comercial' => [SystemRole::SalesRep],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('permite ao administrador convidar para a equipe', function (): void {
    $tenant = TenantScenario::create();
    $admin = $tenant->addStaff(SystemRole::Admin);

    $issued = Fixtures::issue($tenant, $admin, 'paulo.mendes@example.com', SystemRole::Warehouse);

    expect($issued->invitation->kind)->toBe(MembershipKind::Staff)
        ->and($issued->invitation->invited_by_user_id)->toBe($admin->user_id);
});

it('exige a gestão de empresas compradoras para a equipe convidar usuários de comprador', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $salesManager = $tenant->addStaff(SystemRole::SalesManager);
    $salesRep = $tenant->addStaff(SystemRole::SalesRep);

    $issued = Fixtures::issue($tenant, $salesManager, 'compras@mercadoaurora.example.com', SystemRole::Purchaser, $company);

    expect($issued->invitation->kind)->toBe(MembershipKind::Buyer)
        ->and($issued->invitation->buyer_company_id)->toBe($company->id)
        ->and(Fixtures::audits($tenant, 'invitation.issued')[0]->data['buyer_company_id'])->toBe($company->id);

    expect(fn () => Fixtures::issue($tenant, $salesRep, 'outro@mercadoaurora.example.com', SystemRole::Purchaser, $company))
        ->toThrow(AuthorizationException::class);

    expect(Fixtures::pendingCount($tenant, MembershipKind::Buyer))->toBe(1);
});

it('permite ao comprador com gestão de usuários convidar somente para a própria empresa', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $otherCompany = $tenant->addBuyerCompany();
    $buyerAdmin = $tenant->addBuyerUser($company, SystemRole::BuyerAdmin);

    $issued = Fixtures::issue($tenant, $buyerAdmin, 'aprovacoes@ferragensnorte.example.com', SystemRole::Approver, $company);

    expect($issued->invitation->buyer_company_id)->toBe($company->id)
        ->and($issued->invitation->invited_by_user_id)->toBe($buyerAdmin->user_id);

    expect(fn () => Fixtures::issue($tenant, $buyerAdmin, 'intruso@example.com', SystemRole::Purchaser, $otherCompany))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => Fixtures::issue($tenant, $buyerAdmin, 'intruso@example.com', SystemRole::Viewer))
        ->toThrow(AuthorizationException::class);

    expect(Fixtures::pendingCount($tenant, MembershipKind::Buyer))->toBe(1)
        ->and(Fixtures::pendingCount($tenant))->toBe(0);
});

it('recusa convites de comprador sem a permissão de gestão de usuários do portal', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $purchaser = $tenant->addBuyerUser($company, SystemRole::Purchaser);

    Fixtures::issue($tenant, $purchaser, 'colega@ferragensnorte.example.com', SystemRole::Requester, $company);
})->throws(AuthorizationException::class);

it('recusa papel de outro tipo de acesso', function (MembershipKind $kind, SystemRole $role, bool $withCompany): void {
    $tenant = TenantScenario::create();
    $company = $withCompany ? $tenant->addBuyerCompany() : null;

    expect(fn () => Fixtures::issue($tenant, $tenant->ownerMembership, 'lucas.prado@example.com', $role, $company, kind: $kind))
        ->toThrow(RoleKindMismatch::class);

    expect(Fixtures::pendingCount($tenant, $kind))->toBe(0);
    Notification::assertNothingSent();
})->with([
    'papel de comprador em convite de equipe' => [MembershipKind::Staff, SystemRole::Purchaser, false],
    'papel de equipe em convite de comprador' => [MembershipKind::Buyer, SystemRole::SalesRep, true],
]);

it('impede o administrador da empresa compradora de conceder papel da equipe do distribuidor', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $buyerAdmin = $tenant->addBuyerUser($company, SystemRole::BuyerAdmin);

    Fixtures::issue($tenant, $buyerAdmin, 'socio@ferragensnorte.example.com', SystemRole::Owner, $company, kind: MembershipKind::Buyer);
})->throws(RoleKindMismatch::class);

it('permite conceder o papel de proprietário somente a proprietários', function (): void {
    $tenant = TenantScenario::create();
    $admin = $tenant->addStaff(SystemRole::Admin);

    expect(fn () => Fixtures::issue($tenant, $admin, 'nova.socia@example.com', SystemRole::Owner))
        ->toThrow(AuthorizationException::class);
    expect(Fixtures::pendingCount($tenant))->toBe(0);

    $issued = Fixtures::issue($tenant, $tenant->ownerMembership, 'nova.socia@example.com', SystemRole::Owner);

    expect($issued->invitation->role_id)->toBe($tenant->role(SystemRole::Owner)->id);
});

it('recusa convite para e-mail que já possui acesso ativo no mesmo contexto', function (): void {
    $tenant = TenantScenario::create();
    $member = User::factory()->create(['email' => 'rafael.costa@example.com']);
    $tenant->addStaff(SystemRole::SalesRep, $member);

    expect(fn () => Fixtures::issue($tenant, $tenant->ownerMembership, ' RAFAEL.Costa@Example.com', SystemRole::Viewer))
        ->toThrow(AlreadyMember::class);

    $company = $tenant->addBuyerCompany();
    $buyer = User::factory()->create(['email' => 'compras@distribuidoralume.example.com']);
    $tenant->addBuyerUser($company, SystemRole::Purchaser, $buyer);

    expect(fn () => Fixtures::issue($tenant, $tenant->ownerMembership, $buyer->email, SystemRole::Approver, $company))
        ->toThrow(AlreadyMember::class);

    expect(Fixtures::pendingCount($tenant))->toBe(0)
        ->and(Fixtures::pendingCount($tenant, MembershipKind::Buyer))->toBe(0);
});

it('permite convidar e-mail com acesso revogado ou ativo apenas em outro contexto', function (): void {
    $tenant = TenantScenario::create();
    $staffUser = User::factory()->create(['email' => 'rafael.costa@example.com']);
    $tenant->addStaff(SystemRole::SalesRep, $staffUser);
    $company = $tenant->addBuyerCompany();
    $otherCompany = $tenant->addBuyerCompany();
    $buyerUser = User::factory()->create(['email' => 'compras@distribuidoralume.example.com']);
    $tenant->addBuyerUser($company, SystemRole::Purchaser, $buyerUser);

    $revokedUser = User::factory()->create(['email' => 'ex.colaborador@example.com']);
    $revoked = $tenant->addStaff(SystemRole::Viewer, $revokedUser);
    $tenant->actingAs($tenant->ownerMembership, fn () => app(RevokeMembership::class)->handle($revoked, 'Desligamento da empresa'));

    // Equipe ativa convidada como compradora, comprador de outra empresa e ex-membro revogado.
    Fixtures::issue($tenant, $tenant->ownerMembership, $staffUser->email, SystemRole::Purchaser, $company);
    Fixtures::issue($tenant, $tenant->ownerMembership, $buyerUser->email, SystemRole::Purchaser, $otherCompany);
    Fixtures::issue($tenant, $tenant->ownerMembership, $revokedUser->email, SystemRole::Viewer);

    expect(Fixtures::pendingCount($tenant))->toBe(1)
        ->and(Fixtures::pendingCount($tenant, MembershipKind::Buyer))->toBe(2);
});

it('revoga o convite pendente anterior ao reenviar para o mesmo contexto', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $buyerInvitation = Fixtures::issue($tenant, $tenant->ownerMembership, 'beatriz.lima@example.com', SystemRole::Purchaser, $company);

    $first = Fixtures::issue($tenant, $tenant->ownerMembership, 'beatriz.lima@example.com', SystemRole::Viewer);
    $this->clock->advance('2 hours');
    $second = Fixtures::issue($tenant, $tenant->ownerMembership, 'Beatriz.Lima@example.com', SystemRole::SalesRep);

    $previous = Fixtures::invitation($tenant, $first->invitation->id);
    $current = Fixtures::invitation($tenant, $second->invitation->id);

    expect($previous->revoked_at?->equalTo($this->clock->now()))->toBeTrue()
        ->and($current->revoked_at)->toBeNull()
        ->and($current->role_id)->toBe($tenant->role(SystemRole::SalesRep)->id)
        ->and($second->plainToken)->not->toBe($first->plainToken)
        ->and(Fixtures::pendingCount($tenant))->toBe(1)
        // O convite de comprador para o mesmo e-mail é outro contexto e permanece pendente.
        ->and(Fixtures::invitation($tenant, $buyerInvitation->invitation->id)->revoked_at)->toBeNull();

    Notification::assertSentOnDemandTimes(MemberInvitation::class, 3);

    $invitee = User::factory()->create(['email' => 'beatriz.lima@example.com']);

    expect(fn () => Fixtures::accept($tenant, $first->plainToken, $invitee))->toThrow(InvalidInvitation::class);
});

it('conta convites pendentes no limite de usuários da equipe do plano', function (): void {
    $tenant = TenantScenario::create('essencial');
    $tenant->addStaff(SystemRole::SalesRep);
    $tenant->addStaff(SystemRole::Warehouse);
    Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.quatro@example.com', SystemRole::Viewer);
    Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.cinco@example.com', SystemRole::Viewer);
    Notification::fake();

    // 3 ativos + 2 convites pendentes = 5, o limite do plano Essencial.
    try {
        Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.seis@example.com', SystemRole::Viewer);
        $this->fail('O convite acima do limite do plano deveria ser recusado.');
    } catch (PlanLimitReached $e) {
        expect($e->details())->toBe(['limit' => 5, 'resource' => 'staff_users']);
    }

    expect(Fixtures::pendingCount($tenant))->toBe(2)
        ->and($tenant->system(fn (): bool => Invitation::query()->where('email', 'vaga.seis@example.com')->exists()))->toBeFalse();
    Notification::assertNothingSent();

    // Convites de comprador não ocupam vagas da equipe.
    $company = $tenant->addBuyerCompany();
    Fixtures::issue($tenant, $tenant->ownerMembership, 'compras@atacadoserra.example.com', SystemRole::Purchaser, $company);

    expect(Fixtures::pendingCount($tenant, MembershipKind::Buyer))->toBe(1);
});

it('não consome vaga extra no reenvio e libera a vaga de convites revogados ou expirados', function (): void {
    $tenant = TenantScenario::create('essencial');
    $tenant->addStaff(SystemRole::SalesRep);
    $tenant->addStaff(SystemRole::Warehouse);
    Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.quatro@example.com', SystemRole::Viewer);
    $fifth = Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.cinco@example.com', SystemRole::Viewer);

    // No limite: o reenvio revoga o pendente anterior antes de contar as vagas.
    Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.quatro@example.com', SystemRole::Finance);
    expect(Fixtures::pendingCount($tenant))->toBe(2);

    $tenant->actingAs($tenant->ownerMembership, fn () => app(RevokeInvitation::class)->handle($fifth->invitation));
    Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.seis@example.com', SystemRole::Viewer);

    expect(fn () => Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.sete@example.com', SystemRole::Viewer))
        ->toThrow(PlanLimitReached::class);

    // Vencidos os 7 dias, convites não aceitos deixam de reservar vagas.
    $this->clock->advance('7 days');
    Fixtures::issue($tenant, $tenant->ownerMembership, 'vaga.sete@example.com', SystemRole::Viewer);

    expect(Fixtures::pendingCount($tenant))->toBe(3);
});
