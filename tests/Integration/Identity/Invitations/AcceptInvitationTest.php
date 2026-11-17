<?php

declare(strict_types=1);

use App\Actions\Identity\RevokeInvitation;
use App\Actions\Identity\RevokeMembership;
use App\Domain\Identity\Exceptions\ContextUnavailable;
use App\Domain\Identity\Exceptions\InvalidInvitation;
use App\Domain\Identity\Exceptions\InvitationEmailMismatch;
use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Branch;
use App\Models\Identity\User;
use App\Models\Platform\Plan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Integration\Identity\Invitations\InvitationFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Notification::fake();
    $this->clock = Fixtures::freezeClock();
    $this->tenant = TenantScenario::create();
});

it('aceita o convite de equipe ativando a associação com o papel e a filial do convite', function (): void {
    $branch = $this->tenant->system(fn (): Branch => Branch::factory()->create(['name' => 'Filial Joinville']));
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'juliana.ferreira@example.com', SystemRole::SalesRep, branchId: $branch->id);
    $invitee = User::factory()->unverified()->create(['email' => 'juliana.ferreira@example.com']);
    $this->clock->advance('3 days');

    $membership = Fixtures::accept($this->tenant, $issued->plainToken, $invitee);

    expect($membership->tenant_id)->toBe($this->tenant->organization->id)
        ->and($membership->user_id)->toBe($invitee->id)
        ->and($membership->kind)->toBe(MembershipKind::Staff)
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->role_id)->toBe($this->tenant->role(SystemRole::SalesRep)->id)
        ->and($membership->branch_id)->toBe($branch->id)
        ->and($membership->buyer_company_id)->toBeNull()
        ->and($membership->invited_by_user_id)->toBe($this->tenant->owner->id);

    $invitation = Fixtures::invitation($this->tenant, $issued->invitation->id);

    expect($invitation->accepted_at?->equalTo($this->clock->now()))->toBeTrue()
        ->and($invitation->accepted_by_user_id)->toBe($invitee->id)
        // O link recebido por e-mail comprova a posse do endereço.
        ->and($invitee->fresh()->email_verified_at?->equalTo($this->clock->now()))->toBeTrue();

    $audits = Fixtures::audits($this->tenant, 'membership.granted');

    expect($audits)->toHaveCount(1)
        ->and($audits[0]->subject_id)->toBe($membership->id)
        ->and($audits[0]->actor_user_id)->toBe($invitee->id)
        ->and($audits[0]->data)->toMatchArray([
            'via' => 'invitation',
            'invitation_id' => $invitation->id,
            'kind' => 'staff',
            'role_id' => $membership->role_id,
        ]);
});

it('aceita o convite de comprador vinculando a associação à empresa compradora', function (): void {
    $company = $this->tenant->addBuyerCompany();
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'compras@mercadoaurora.example.com', SystemRole::Approver, $company);
    $invitee = User::factory()->create(['email' => 'compras@mercadoaurora.example.com']);

    $membership = Fixtures::accept($this->tenant, $issued->plainToken, $invitee);

    expect($membership->kind)->toBe(MembershipKind::Buyer)
        ->and($membership->buyer_company_id)->toBe($company->id)
        ->and($membership->role_id)->toBe($this->tenant->role(SystemRole::Approver)->id)
        ->and($membership->executionContext()->buyerCompanyId)->toBe($company->id);
});

it('preserva a data de verificação de e-mail já existente', function (): void {
    $verifiedAt = $this->clock->now()->subMonths(2);
    $invitee = User::factory()->create(['email' => 'ricardo.nunes@example.com', 'email_verified_at' => $verifiedAt]);
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, $invitee->email, SystemRole::Viewer);

    Fixtures::accept($this->tenant, $issued->plainToken, $invitee);

    expect($invitee->fresh()->email_verified_at?->equalTo($verifiedAt))->toBeTrue();
});

it('compara o e-mail do convite com o do usuário sem diferenciar maiúsculas', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'helena.rocha@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'Helena.Rocha@Example.com']);

    expect(Fixtures::accept($this->tenant, $issued->plainToken, $invitee)->isActive())->toBeTrue();
});

it('recusa token inexistente', function (): void {
    $invitee = User::factory()->create();

    Fixtures::accept($this->tenant, Str::random(64), $invitee);
})->throws(InvalidInvitation::class);

it('aceita até o último segundo da validade e recusa o convite vencido', function (): void {
    $onTime = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'no.prazo@example.com', SystemRole::Viewer);
    $late = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'atrasado@example.com', SystemRole::Viewer);
    $onTimeUser = User::factory()->create(['email' => 'no.prazo@example.com']);
    $lateUser = User::factory()->create(['email' => 'atrasado@example.com']);

    // 7 dias menos 1 segundo após a emissão.
    $this->clock->advance('604799 seconds');
    Fixtures::accept($this->tenant, $onTime->plainToken, $onTimeUser);

    $this->clock->advance('1 second');

    expect(fn () => Fixtures::accept($this->tenant, $late->plainToken, $lateUser))->toThrow(InvalidInvitation::class)
        ->and(Fixtures::membershipsOf($this->tenant, $lateUser))->toBe([])
        ->and(Fixtures::invitation($this->tenant, $late->invitation->id)->accepted_at)->toBeNull();
});

it('recusa convite revogado', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'diego.martins@example.com', SystemRole::Viewer);
    $this->tenant->actingAs($this->tenant->ownerMembership, fn () => app(RevokeInvitation::class)->handle($issued->invitation));
    $invitee = User::factory()->create(['email' => 'diego.martins@example.com']);

    expect(fn () => Fixtures::accept($this->tenant, $issued->plainToken, $invitee))->toThrow(InvalidInvitation::class)
        ->and(Fixtures::membershipsOf($this->tenant, $invitee))->toBe([]);
});

it('recusa o aceite por conta de outro e-mail e mantém o convite disponível ao destinatário', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'camila.duarte@example.com', SystemRole::Viewer);
    $intruder = User::factory()->create(['email' => 'outra.pessoa@example.com']);
    $invitee = User::factory()->create(['email' => 'camila.duarte@example.com']);

    expect(fn () => Fixtures::accept($this->tenant, $issued->plainToken, $intruder))->toThrow(InvitationEmailMismatch::class)
        ->and(Fixtures::membershipsOf($this->tenant, $intruder))->toBe([])
        ->and(Fixtures::invitation($this->tenant, $issued->invitation->id)->accepted_at)->toBeNull();

    expect(Fixtures::accept($this->tenant, $issued->plainToken, $invitee)->user_id)->toBe($invitee->id);
});

it('recusa organização inexistente ou identificador inválido na URL', function (string $organizationId): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'fernanda.reis@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'fernanda.reis@example.com']);

    expect(fn () => Fixtures::accept($organizationId, $issued->plainToken, $invitee))->toThrow(InvalidInvitation::class)
        ->and(Fixtures::invitation($this->tenant, $issued->invitation->id)->accepted_at)->toBeNull();
})->with([
    'UUID sem organização' => ['0195f0c2-6a3b-7c1d-9e2f-3a4b5c6d7e8f'],
    'texto que não é UUID' => ['distribuidora-exemplo'],
    'injeção na URL' => ["' or 1=1 --"],
]);

it('recusa token válido de outro tenant apresentado com a organização adulterada', function (): void {
    $other = TenantScenario::create();
    $issued = Fixtures::issue($other, $other->ownerMembership, 'gustavo.pires@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'gustavo.pires@example.com']);

    expect(fn () => Fixtures::accept($this->tenant, $issued->plainToken, $invitee))->toThrow(InvalidInvitation::class)
        ->and(Fixtures::membershipsOf($this->tenant, $invitee))->toBe([])
        ->and(Fixtures::membershipsOf($other, $invitee))->toBe([])
        ->and(Fixtures::invitation($other, $issued->invitation->id)->accepted_at)->toBeNull();
});

it('recusa o aceite quando a organização não permite acesso', function (OrganizationStatus $status): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'renato.alves@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'renato.alves@example.com']);
    $this->tenant->organization->forceFill(['status' => $status])->save();

    expect(fn () => Fixtures::accept($this->tenant, $issued->plainToken, $invitee))->toThrow(ContextUnavailable::class)
        ->and(Fixtures::membershipsOf($this->tenant, $invitee))->toBe([])
        ->and(Fixtures::invitation($this->tenant, $issued->invitation->id)->accepted_at)->toBeNull();
})->with([
    'suspensa' => [OrganizationStatus::Suspended],
    'cancelada' => [OrganizationStatus::Cancelled],
]);

it('trata o aceite repetido pelo mesmo usuário de forma idempotente', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'otavio.batista@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'otavio.batista@example.com']);

    $first = Fixtures::accept($this->tenant, $issued->plainToken, $invitee);
    $acceptedAt = $this->clock->now();
    $this->clock->advance('1 hour');
    $second = Fixtures::accept($this->tenant, $issued->plainToken, $invitee);

    expect($second->id)->toBe($first->id)
        ->and($second->isActive())->toBeTrue()
        ->and(Fixtures::membershipsOf($this->tenant, $invitee))->toHaveCount(1)
        ->and(Fixtures::invitation($this->tenant, $issued->invitation->id)->accepted_at?->equalTo($acceptedAt))->toBeTrue()
        ->and(Fixtures::audits($this->tenant, 'membership.granted'))->toHaveCount(1);
});

it('não restaura acesso revogado quando o convite já aceito é reapresentado', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'sergio.campos@example.com', SystemRole::SalesRep);
    $invitee = User::factory()->create(['email' => 'sergio.campos@example.com']);
    $membership = Fixtures::accept($this->tenant, $issued->plainToken, $invitee);
    $this->tenant->actingAs($this->tenant->ownerMembership, fn () => app(RevokeMembership::class)->handle($membership, 'Encerramento do contrato'));

    expect(fn () => Fixtures::accept($this->tenant, $issued->plainToken, $invitee))->toThrow(InvalidInvitation::class);

    $memberships = Fixtures::membershipsOf($this->tenant, $invitee);

    expect($memberships)->toHaveCount(1)
        ->and($memberships[0]->status)->toBe(MembershipStatus::Revoked);
});

it('reativa a associação revogada com o papel do novo convite', function (): void {
    $user = User::factory()->create(['email' => 'aline.teixeira@example.com']);
    $old = $this->tenant->addStaff(SystemRole::Viewer, $user);
    $this->tenant->actingAs($this->tenant->ownerMembership, fn () => app(RevokeMembership::class)->handle($old, 'Afastamento temporário'));
    $admin = $this->tenant->addStaff(SystemRole::Admin);

    $issued = Fixtures::issue($this->tenant, $admin, $user->email, SystemRole::SalesManager);
    $membership = Fixtures::accept($this->tenant, $issued->plainToken, $user);

    expect($membership->id)->toBe($old->id)
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->role_id)->toBe($this->tenant->role(SystemRole::SalesManager)->id)
        ->and($membership->invited_by_user_id)->toBe($admin->user_id)
        ->and($membership->revoked_at)->toBeNull()
        ->and($membership->revoked_by_user_id)->toBeNull()
        ->and($membership->revoke_reason)->toBeNull()
        ->and(Fixtures::membershipsOf($this->tenant, $user))->toHaveCount(1)
        ->and(Fixtures::audits($this->tenant, 'membership.granted'))->toHaveCount(1);
});

it('permite que o convite pendente ocupe a última vaga do plano no aceite', function (): void {
    $tenant = TenantScenario::create('essencial');
    $tenant->addStaff(SystemRole::SalesRep);
    $tenant->addStaff(SystemRole::Warehouse);
    $tenant->addStaff(SystemRole::Finance);
    $issued = Fixtures::issue($tenant, $tenant->ownerMembership, 'quinta.vaga@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'quinta.vaga@example.com']);

    expect(Fixtures::accept($tenant, $issued->plainToken, $invitee)->isActive())->toBeTrue();
});

it('recusa o aceite de equipe quando os usuários ativos já atingiram o limite reduzido do plano', function (): void {
    $tenant = TenantScenario::create('essencial');
    $tenant->addStaff(SystemRole::SalesRep);
    $tenant->addStaff(SystemRole::Warehouse);
    $tenant->addStaff(SystemRole::Finance);
    $staffInvitation = Fixtures::issue($tenant, $tenant->ownerMembership, 'quinta.vaga@example.com', SystemRole::Viewer);
    $company = $tenant->addBuyerCompany();
    $buyerInvitation = Fixtures::issue($tenant, $tenant->ownerMembership, 'compras@atacadoserra.example.com', SystemRole::Purchaser, $company);
    $staffInvitee = User::factory()->create(['email' => 'quinta.vaga@example.com']);
    $buyerInvitee = User::factory()->create(['email' => 'compras@atacadoserra.example.com']);

    Plan::query()->where('code', 'essencial')->update(['max_staff_users' => 4]);

    try {
        Fixtures::accept($tenant, $staffInvitation->plainToken, $staffInvitee);
        $this->fail('O aceite acima do limite do plano deveria ser recusado.');
    } catch (PlanLimitReached $e) {
        expect($e->details())->toBe(['limit' => 4, 'resource' => 'staff_users']);
    }

    expect(Fixtures::membershipsOf($tenant, $staffInvitee))->toBe([])
        ->and(Fixtures::invitation($tenant, $staffInvitation->invitation->id)->accepted_at)->toBeNull();

    // Acessos de comprador não dependem das vagas da equipe.
    expect(Fixtures::accept($tenant, $buyerInvitation->plainToken, $buyerInvitee)->kind)->toBe(MembershipKind::Buyer);
});
