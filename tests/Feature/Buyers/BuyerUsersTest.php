<?php

declare(strict_types=1);

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Buyers\BuyerUsers;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Notifications\Identity\MemberInvitation;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Notification::fake();
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);
    $this->as = fn (Closure $callback, ?Membership $actor = null): mixed => Fixtures::livewireAs($this->tenant, $actor ?? $this->manager, $callback);

    $this->stored = fn (Membership $membership): Membership => $this->tenant->system(
        static fn (): Membership => Membership::query()->findOrFail($membership->id),
    );

    $this->invitations = fn (): array => $this->tenant->system(static fn (): array => Invitation::query()
        ->whereNull('revoked_at')
        ->orderBy('email')
        ->get()
        ->map(static fn (Invitation $i): array => [$i->email, $i->kind->value, $i->buyer_company_id])
        ->all());
});

it('lista os usuários da empresa e convida com papel de comprador', function (): void {
    $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin, User::factory()->create(['name' => 'Juliana Prado Siqueira']));
    $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::Purchaser, User::factory()->create(['name' => 'Usuário de outra empresa']));
    $purchaserRoleId = $this->tenant->role(SystemRole::Purchaser)->id;

    ($this->as)(fn () => Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
        ->assertSee('Juliana Prado Siqueira')
        ->assertDontSee('Usuário de outra empresa')
        ->assertSee('Comprador')
        ->assertDontSee('Gerente comercial')
        ->set('inviteEmail', 'Marcos.Teixeira@SerraAzul.example')
        ->set('inviteRoleId', $purchaserRoleId)
        ->call('invite')
        ->assertHasNoErrors()
        ->assertSet('message', 'Convite enviado para Marcos.Teixeira@SerraAzul.example.')
        ->assertSet('inviteEmail', '')
        ->assertSee('marcos.teixeira@serraazul.example')
        ->assertDispatched('buyer-updated'));

    expect(($this->invitations)())->toBe([['marcos.teixeira@serraazul.example', MembershipKind::Buyer->value, $this->company->id]]);
    Notification::assertSentOnDemandTimes(MemberInvitation::class, 1);
});

it('recusa papel da equipe no convite de usuário da empresa', function (): void {
    $staffRoleId = $this->tenant->role(SystemRole::SalesManager)->id;

    ($this->as)(fn () => Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
        ->set('inviteEmail', 'marcos.teixeira@serraazul.example')
        ->set('inviteRoleId', $staffRoleId)
        ->call('invite')
        ->assertHasErrors(['inviteEmail' => 'O papel escolhido não pertence a este tipo de acesso.'])
        ->assertSet('message', null));

    expect(($this->invitations)())->toBe([]);
    Notification::assertNothingSent();
});

it('trata papel de outro distribuidor como inexistente no convite', function (): void {
    $foreignRoleId = TenantScenario::create()->role(SystemRole::Purchaser)->id;

    ($this->as)(fn () => Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
        ->set('inviteEmail', 'marcos.teixeira@serraazul.example')
        ->set('inviteRoleId', $foreignRoleId)
        ->call('invite')
        ->assertNotFound());

    expect(($this->invitations)())->toBe([]);
});

it('revoga o acesso com justificativa e recusa justificativa curta', function (): void {
    $buyer = $this->tenant->addBuyerUser($this->company, SystemRole::Purchaser);

    ($this->as)(fn () => Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
        ->set('revokeReason', 'ok')
        ->call('revoke', $buyer->id)
        ->assertHasErrors(['revokeReason' => 'Informe a justificativa (mínimo de 5 caracteres).'])
        ->set('revokeReason', 'Desligamento do comprador da empresa')
        ->call('revoke', $buyer->id)
        ->assertHasNoErrors()
        ->assertSet('message', 'Acesso revogado.')
        ->assertSet('revokeReason', '')
        ->assertSee('Desligamento do comprador da empresa'));

    $stored = ($this->stored)($buyer);

    expect($stored->status)->toBe(MembershipStatus::Revoked)
        ->and($stored->revoke_reason)->toBe('Desligamento do comprador da empresa')
        ->and(Fixtures::audits($this->tenant, 'membership.revoked'))->toHaveCount(1);
});

it('trata associação de outra empresa, da equipe ou de outro distribuidor como inexistente', function (): void {
    $sibling = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::Purchaser);
    $staff = $this->tenant->addStaff(SystemRole::SalesRep);
    $other = TenantScenario::create();
    $foreign = $other->addBuyerUser($other->addBuyerCompany(), SystemRole::Purchaser);

    ($this->as)(function () use ($sibling, $staff, $foreign): void {
        foreach ([$sibling->id, $staff->id, $foreign->id, 'associacao-invalida'] as $id) {
            Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
                ->set('revokeReason', 'Identificador adulterado no navegador')
                ->call('revoke', $id)
                ->assertNotFound();
        }
    });

    expect(($this->stored)($sibling)->status)->toBe(MembershipStatus::Active)
        ->and(($this->stored)($staff)->status)->toBe(MembershipStatus::Active)
        ->and($other->system(static fn (): string => Membership::query()->findOrFail($foreign->id)->status->value))->toBe('active')
        ->and(Fixtures::audits($this->tenant, 'membership.revoked'))->toBeEmpty();
});

it('cancela convite da empresa e trata convite de outra empresa como inexistente', function (): void {
    $purchaserRoleId = $this->tenant->role(SystemRole::Purchaser)->id;
    $otherCompany = $this->tenant->addBuyerCompany();

    ($this->as)(function () use ($purchaserRoleId, $otherCompany): void {
        Livewire::test(BuyerUsers::class, ['buyerId' => $otherCompany->id])
            ->set('inviteEmail', 'contato@outraempresa.example')
            ->set('inviteRoleId', $purchaserRoleId)
            ->call('invite')
            ->assertHasNoErrors();

        $component = Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
            ->set('inviteEmail', 'marcos.teixeira@serraazul.example')
            ->set('inviteRoleId', $purchaserRoleId)
            ->call('invite');

        $own = Invitation::query()->where('buyer_company_id', $this->company->id)->sole();
        $sibling = Invitation::query()->where('buyer_company_id', $otherCompany->id)->sole();

        Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])->call('cancelInvitation', $sibling->id)->assertNotFound();

        $component->call('cancelInvitation', $own->id)
            ->assertSet('message', 'Convite cancelado.')
            ->assertDontSee('marcos.teixeira@serraazul.example');
    });

    expect(($this->invitations)())->toBe([['contato@outraempresa.example', 'buyer', $otherCompany->id]]);
});

it('refaz a autorização ao convidar e revogar', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    Tests\Integration\Ordering\Staff\StaffOrderFixtures::assignRep($this->tenant, $this->company, $seller);
    $buyer = $this->tenant->addBuyerUser($this->company, SystemRole::Purchaser);
    $purchaserRoleId = $this->tenant->role(SystemRole::Purchaser)->id;

    ($this->as)(function () use ($buyer, $purchaserRoleId): void {
        Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
            ->assertDontSee('Enviar convite')
            ->set('inviteEmail', 'marcos.teixeira@serraazul.example')
            ->set('inviteRoleId', $purchaserRoleId)
            ->call('invite')
            ->assertForbidden();

        Livewire::test(BuyerUsers::class, ['buyerId' => $this->company->id])
            ->set('revokeReason', 'Tentativa sem permissão de gestão')
            ->call('revoke', $buyer->id)
            ->assertForbidden();
    }, $seller);

    expect(($this->invitations)())->toBe([])
        ->and(($this->stored)($buyer)->status)->toBe(MembershipStatus::Active);
});
