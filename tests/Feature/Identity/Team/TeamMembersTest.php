<?php

declare(strict_types=1);

use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Http\Middleware\EstablishRequestContext;
use App\Livewire\Staff\TeamMembers;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();

    // O componente roda como a associação informada: contexto de banco e
    // Access preenchidos como faria o middleware, e usuário autenticado.
    $this->asMember = fn (Membership $membership, Closure $callback): mixed => $this->tenant->actingAs(
        $membership,
        static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        },
    );

    $this->stored = fn (Membership $membership, ?TenantScenario $tenant = null): Membership => ($tenant ?? $this->tenant)->system(
        static fn (): Membership => Membership::query()->with('role')->findOrFail($membership->id),
    );

    $this->membershipLogs = fn (?TenantScenario $tenant = null) => ($tenant ?? $this->tenant)->system(
        static fn () => AuditLog::query()->whereIn('action', ['membership.revoked', 'membership.role_changed'])->get(),
    );
});

it('nega a página da equipe a quem não tem members.manage', function (SystemRole $role): void {
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $this->tenant->addStaff($role, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('team.index'))
        ->assertForbidden();
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'gerente comercial' => [SystemRole::SalesManager],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('nega a página da equipe no contexto de empresa compradora, mesmo com portal.users', function (): void {
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('team.index'))
        ->assertForbidden();
});

it('exibe a página da equipe ao administrador com segundo fator', function (): void {
    $admin = User::factory()->withTwoFactor()->create(['name' => 'Helena Duarte Vasconcelos'])->fresh();
    $membership = $this->tenant->addStaff(SystemRole::Admin, $admin);
    $this->tenant->addStaff(SystemRole::SalesRep, User::factory()->create(['name' => 'Rafael Antunes Moreira']));

    $this->actingAs($admin)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('team.index'))
        ->assertOk()
        ->assertSee('Equipe e permissões')
        ->assertSee('Rafael Antunes Moreira');
});

it('revoga pela tela e a sessão antiga do usuário revogado deixa de valer na requisição seguinte', function (): void {
    $sellerUser = User::factory()->create(['name' => 'Rafael Antunes Moreira'])->fresh();
    $seller = $this->tenant->addStaff(SystemRole::SalesRep, $sellerUser);
    $oldSession = TenantScenario::sessionFor($seller);

    $this->actingAs($sellerUser)->withSession($oldSession)->get(route('dashboard'))->assertOk();

    ($this->asMember)($this->tenant->ownerMembership, static fn () => Livewire::test(TeamMembers::class)
        ->set('revokeReason', 'Encerramento do contrato de representação')
        ->call('revoke', $seller->id)
        ->assertHasNoErrors()
        ->assertSet('message', 'Acesso revogado.')
        ->assertSet('revokeReason', ''));

    $this->actingAs($sellerUser)
        ->withSession($oldSession)
        ->get(route('dashboard'))
        ->assertRedirect(route('contexts.index'))
        ->assertSessionMissing(EstablishRequestContext::SESSION_KEY)
        ->assertSessionHas('status', 'O acesso a este contexto não está mais disponível.');

    $stored = ($this->stored)($seller);

    expect($stored->status)->toBe(MembershipStatus::Revoked)
        ->and($stored->revoke_reason)->toBe('Encerramento do contrato de representação')
        ->and($stored->revoked_by_user_id)->toBe($this->tenant->owner->id);
});

it('exige justificativa na tela e mantém o acesso', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    ($this->asMember)($this->tenant->ownerMembership, static fn () => Livewire::test(TeamMembers::class)
        ->set('revokeReason', 'ok')
        ->call('revoke', $seller->id)
        ->assertHasErrors(['revokeReason' => 'Informe a justificativa (mínimo de 5 caracteres).'])
        ->assertSet('message', null));

    expect(($this->stored)($seller)->status)->toBe(MembershipStatus::Active)
        ->and(($this->membershipLogs)())->toBeEmpty();
});

it('exibe a regra do último proprietário sem revogar o acesso', function (): void {
    $owner = $this->tenant->ownerMembership;

    ($this->asMember)($owner, static fn () => Livewire::test(TeamMembers::class)
        ->set('revokeReason', 'Encerramento das atividades')
        ->call('revoke', $owner->id)
        ->assertHasErrors(['revokeReason' => 'A organização precisa manter ao menos um proprietário ativo.'])
        ->assertSet('message', null));

    expect(($this->stored)($owner)->status)->toBe(MembershipStatus::Active);
});

it('refaz a autorização nas ações da tela, independentemente da rota', function (): void {
    $sellerUser = User::factory()->create()->fresh();
    $actor = $this->tenant->addStaff(SystemRole::SalesRep, $sellerUser);
    $colleague = $this->tenant->addStaff(SystemRole::SalesRep);
    $managerRoleId = $this->tenant->role(SystemRole::SalesManager)->id;

    ($this->asMember)($actor, static function () use ($colleague, $managerRoleId): void {
        Livewire::test(TeamMembers::class)
            ->set('revokeReason', 'Tentativa sem permissão de gestão')
            ->call('revoke', $colleague->id)
            ->assertForbidden();

        Livewire::test(TeamMembers::class)
            ->set("roleSelections.{$colleague->id}", $managerRoleId)
            ->call('changeRole', $colleague->id)
            ->assertForbidden();
    });

    $stored = ($this->stored)($colleague);

    expect($stored->status)->toBe(MembershipStatus::Active)
        ->and($stored->role->key)->toBe(SystemRole::SalesRep->value)
        ->and(($this->membershipLogs)())->toBeEmpty();
});

it('trata como inexistente a associação de outro tenant nas ações da tela', function (): void {
    $other = TenantScenario::create();
    $foreign = $other->addStaff(SystemRole::SalesRep);
    $foreignAdminRoleId = $other->role(SystemRole::Admin)->id;

    ($this->asMember)($this->tenant->ownerMembership, static function () use ($foreign, $foreignAdminRoleId): void {
        Livewire::test(TeamMembers::class)
            ->set('revokeReason', 'Identificador de outro distribuidor')
            ->call('revoke', $foreign->id)
            ->assertNotFound();

        Livewire::test(TeamMembers::class)
            ->set("roleSelections.{$foreign->id}", $foreignAdminRoleId)
            ->call('changeRole', $foreign->id)
            ->assertNotFound();
    });

    $stored = ($this->stored)($foreign, $other);

    expect($stored->status)->toBe(MembershipStatus::Active)
        ->and($stored->role->key)->toBe(SystemRole::SalesRep->value)
        ->and(($this->membershipLogs)($other))->toBeEmpty();
});

it('trata como inexistente a associação de comprador na tela da equipe', function (): void {
    $buyer = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin);
    $purchaserRoleId = $this->tenant->role(SystemRole::Purchaser)->id;

    ($this->asMember)($this->tenant->ownerMembership, static function () use ($buyer, $purchaserRoleId): void {
        Livewire::test(TeamMembers::class)
            ->set('revokeReason', 'Acesso de comprador pela tela da equipe')
            ->call('revoke', $buyer->id)
            ->assertNotFound();

        Livewire::test(TeamMembers::class)
            ->set("roleSelections.{$buyer->id}", $purchaserRoleId)
            ->call('changeRole', $buyer->id)
            ->assertNotFound();
    });

    $stored = ($this->stored)($buyer);

    expect($stored->status)->toBe(MembershipStatus::Active)
        ->and($stored->role->key)->toBe(SystemRole::BuyerAdmin->value)
        ->and(($this->membershipLogs)())->toBeEmpty();
});

it('trata identificador malformado como associação inexistente', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    ($this->asMember)($this->tenant->ownerMembership, static fn () => Livewire::test(TeamMembers::class)
        ->set('revokeReason', 'Identificador adulterado no navegador')
        ->call('revoke', "{$seller->id}' or '1'='1")
        ->assertNotFound());

    expect(($this->stored)($seller)->status)->toBe(MembershipStatus::Active)
        ->and(($this->membershipLogs)())->toBeEmpty();
});

it('altera o papel escolhido na tela', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $managerRoleId = $this->tenant->role(SystemRole::SalesManager)->id;

    ($this->asMember)($this->tenant->ownerMembership, static fn () => Livewire::test(TeamMembers::class)
        ->assertSet("roleSelections.{$seller->id}", $seller->role_id)
        ->set("roleSelections.{$seller->id}", $managerRoleId)
        ->call('changeRole', $seller->id)
        ->assertSet('message', 'Papel atualizado.'));

    expect(($this->stored)($seller)->role->key)->toBe(SystemRole::SalesManager->value);
});

it('não aplica papel de outro tenant escolhido na tela', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $foreignRoleId = TenantScenario::create()->role(SystemRole::SalesManager)->id;

    ($this->asMember)($this->tenant->ownerMembership, static fn () => Livewire::test(TeamMembers::class)
        ->set("roleSelections.{$seller->id}", $foreignRoleId)
        ->call('changeRole', $seller->id)
        ->assertNotFound());

    expect(($this->stored)($seller)->role->key)->toBe(SystemRole::SalesRep->value)
        ->and(($this->membershipLogs)())->toBeEmpty();
});

it('não aplica papel de comprador escolhido na tela e restaura a seleção', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $buyerAdminRoleId = $this->tenant->role(SystemRole::BuyerAdmin)->id;

    ($this->asMember)($this->tenant->ownerMembership, static fn () => Livewire::test(TeamMembers::class)
        ->set("roleSelections.{$seller->id}", $buyerAdminRoleId)
        ->call('changeRole', $seller->id)
        ->assertOk()
        ->assertSet('message', 'O papel escolhido não pertence a este tipo de acesso.')
        ->assertSet("roleSelections.{$seller->id}", $seller->role_id));

    expect(($this->stored)($seller)->role->key)->toBe(SystemRole::SalesRep->value)
        ->and(($this->membershipLogs)())->toBeEmpty();
});
