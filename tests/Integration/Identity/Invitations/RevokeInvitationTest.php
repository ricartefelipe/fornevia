<?php

declare(strict_types=1);

use App\Domain\Identity\Exceptions\InvalidInvitation;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Tests\Integration\Identity\Invitations\InvitationFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Notification::fake();
    $this->clock = Fixtures::freezeClock();
    $this->tenant = TenantScenario::create();
});

it('revoga o convite pendente, audita e invalida o token', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'leticia.moura@example.com', SystemRole::Viewer);
    $this->clock->advance('1 day');

    Fixtures::revoke($this->tenant, $this->tenant->ownerMembership, $issued->invitation);

    $invitation = Fixtures::invitation($this->tenant, $issued->invitation->id);
    $audits = Fixtures::audits($this->tenant, 'invitation.revoked');

    expect($invitation->revoked_at?->equalTo($this->clock->now()))->toBeTrue()
        ->and(Fixtures::pendingCount($this->tenant))->toBe(0)
        ->and($audits)->toHaveCount(1)
        ->and($audits[0]->subject_id)->toBe($invitation->id)
        ->and($audits[0]->actor_user_id)->toBe($this->tenant->owner->id)
        ->and($audits[0]->data)->toBe(['email' => 'leticia.moura@example.com']);

    $invitee = User::factory()->create(['email' => 'leticia.moura@example.com']);

    expect(fn () => Fixtures::accept($this->tenant, $issued->plainToken, $invitee))->toThrow(InvalidInvitation::class);
});

it('não altera convite já aceito nem registra nova revogação de convite já revogado', function (): void {
    $accepted = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'tiago.freitas@example.com', SystemRole::Viewer);
    $invitee = User::factory()->create(['email' => 'tiago.freitas@example.com']);
    $membership = Fixtures::accept($this->tenant, $accepted->plainToken, $invitee);
    $revoked = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'vanessa.rangel@example.com', SystemRole::Viewer);

    Fixtures::revoke($this->tenant, $this->tenant->ownerMembership, $accepted->invitation);
    Fixtures::revoke($this->tenant, $this->tenant->ownerMembership, $revoked->invitation);
    $firstRevocation = $this->clock->now();
    $this->clock->advance('1 hour');
    Fixtures::revoke($this->tenant, $this->tenant->ownerMembership, $revoked->invitation);

    $acceptedNow = Fixtures::invitation($this->tenant, $accepted->invitation->id);

    expect($acceptedNow->revoked_at)->toBeNull()
        ->and($acceptedNow->accepted_by_user_id)->toBe($invitee->id)
        ->and($this->tenant->system(fn (): bool => Membership::query()->findOrFail($membership->id)->isActive()))->toBeTrue()
        ->and(Fixtures::invitation($this->tenant, $revoked->invitation->id)->revoked_at?->equalTo($firstRevocation))->toBeTrue()
        ->and(Fixtures::audits($this->tenant, 'invitation.revoked'))->toHaveCount(1);
});

it('exige a gestão de equipe para revogar convite de equipe', function (): void {
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'joao.batista@example.com', SystemRole::Viewer);
    $salesManager = $this->tenant->addStaff(SystemRole::SalesManager);

    expect(fn () => Fixtures::revoke($this->tenant, $salesManager, $issued->invitation))->toThrow(AuthorizationException::class)
        ->and(Fixtures::invitation($this->tenant, $issued->invitation->id)->revoked_at)->toBeNull();
});

it('permite à equipe com gestão de compradores revogar convites de comprador', function (): void {
    $company = $this->tenant->addBuyerCompany();
    $issued = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'compras@mercadoaurora.example.com', SystemRole::Purchaser, $company);
    $salesManager = $this->tenant->addStaff(SystemRole::SalesManager);
    $salesRep = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => Fixtures::revoke($this->tenant, $salesRep, $issued->invitation))->toThrow(AuthorizationException::class);

    Fixtures::revoke($this->tenant, $salesManager, $issued->invitation);

    expect(Fixtures::invitation($this->tenant, $issued->invitation->id)->revoked_at)->not->toBeNull();
});

it('restringe o administrador da empresa compradora aos convites da própria empresa', function (): void {
    $company = $this->tenant->addBuyerCompany();
    $otherCompany = $this->tenant->addBuyerCompany();
    $buyerAdmin = $this->tenant->addBuyerUser($company, SystemRole::BuyerAdmin);
    $own = Fixtures::issue($this->tenant, $buyerAdmin, 'colega@ferragensnorte.example.com', SystemRole::Requester, $company);
    $foreign = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'compras@outraempresa.example.com', SystemRole::Purchaser, $otherCompany);
    $staff = Fixtures::issue($this->tenant, $this->tenant->ownerMembership, 'equipe@example.com', SystemRole::Viewer);

    expect(fn () => Fixtures::revoke($this->tenant, $buyerAdmin, $foreign->invitation))->toThrow(AuthorizationException::class)
        ->and(fn () => Fixtures::revoke($this->tenant, $buyerAdmin, $staff->invitation))->toThrow(AuthorizationException::class);

    Fixtures::revoke($this->tenant, $buyerAdmin, $own->invitation);

    expect(Fixtures::invitation($this->tenant, $own->invitation->id)->revoked_at)->not->toBeNull()
        ->and(Fixtures::invitation($this->tenant, $foreign->invitation->id)->revoked_at)->toBeNull()
        ->and(Fixtures::invitation($this->tenant, $staff->invitation->id)->revoked_at)->toBeNull();
});
