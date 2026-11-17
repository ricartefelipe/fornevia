<?php

declare(strict_types=1);

use App\Actions\Identity\AuthorizesMemberManagement;
use App\Actions\Identity\ChangeMembershipRole;
use App\Actions\Identity\RevokeMembership;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço']);
    $this->otherCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Rede Farmácia Central']);

    // A associação-alvo é carregada no contexto de sistema e entregue à Action,
    // como faria uma camada de entrada que recebesse um identificador adulterado.
    $this->load = fn (Membership $membership): Membership => $this->tenant->system(
        static fn (): Membership => Membership::query()->with('role')->findOrFail($membership->id),
    );

    $this->revoke = fn (Membership $actor, Membership $target): Membership => $this->tenant->actingAs(
        $actor,
        static fn (): Membership => app(RevokeMembership::class)->handle($target, 'Usuário deixou a empresa'),
    );

    $this->change = function (Membership $actor, Membership $target, SystemRole $role): Membership {
        $roleId = $this->tenant->role($role)->id;

        return $this->tenant->actingAs(
            $actor,
            static fn (): Membership => app(ChangeMembershipRole::class)->handle($target, $roleId),
        );
    };

    $this->logsFor = fn (Membership $membership) => $this->tenant->system(
        static fn () => AuditLog::query()->where('subject_id', $membership->id)
            ->whereIn('action', ['membership.revoked', 'membership.role_changed'])
            ->get(),
    );
});

it('permite ao comprador com portal.users gerenciar os usuários da própria empresa', function (): void {
    $buyerAdmin = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $purchaser = $this->tenant->addBuyerUser($this->company, SystemRole::Purchaser);
    $requester = $this->tenant->addBuyerUser($this->company, SystemRole::Requester);

    ($this->change)($buyerAdmin, ($this->load)($requester), SystemRole::Approver);
    ($this->revoke)($buyerAdmin, ($this->load)($purchaser));

    expect(($this->load)($requester)->role->key)->toBe(SystemRole::Approver->value)
        ->and(($this->load)($purchaser)->status)->toBe(MembershipStatus::Revoked)
        ->and(($this->load)($purchaser)->revoked_by_user_id)->toBe($buyerAdmin->user_id);

    expect(($this->logsFor)($purchaser)->first()?->actor_membership_id)->toBe($buyerAdmin->id)
        ->and(($this->logsFor)($requester)->first()?->data)->toEqual([
            'from' => SystemRole::Requester->value,
            'to' => SystemRole::Approver->value,
        ]);
});

it('impede o comprador com portal.users de gerenciar usuários de outra empresa compradora', function (): void {
    $buyerAdmin = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $foreignPurchaser = $this->tenant->addBuyerUser($this->otherCompany, SystemRole::Purchaser);
    $target = ($this->load)($foreignPurchaser);

    expect(fn () => ($this->revoke)($buyerAdmin, $target))
        ->toThrow(AuthorizationException::class, 'Sem permissão para gerenciar acessos desta empresa.')
        ->and(fn () => ($this->change)($buyerAdmin, $target, SystemRole::BuyerAdmin))
        ->toThrow(AuthorizationException::class, 'Sem permissão para gerenciar acessos desta empresa.');

    $stored = ($this->load)($foreignPurchaser);

    expect($stored->status)->toBe(MembershipStatus::Active)
        ->and($stored->role->key)->toBe(SystemRole::Purchaser->value)
        ->and(($this->logsFor)($foreignPurchaser))->toBeEmpty();
});

it('não autoriza comprador quando a empresa do acesso-alvo não é informada', function (): void {
    $buyerAdmin = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);

    expect(fn () => $this->tenant->actingAs(
        $buyerAdmin,
        static fn () => app(AuthorizesMemberManagement::class)->authorize(MembershipKind::Buyer, null),
    ))->toThrow(AuthorizationException::class);
});

it('impede o comprador sem portal.users de gerenciar colegas da mesma empresa', function (SystemRole $role): void {
    $actor = $this->tenant->addBuyerUser($this->company, $role);
    $colleague = $this->tenant->addBuyerUser($this->company, SystemRole::Requester);
    $target = ($this->load)($colleague);

    expect(fn () => ($this->revoke)($actor, $target))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->change)($actor, $target, SystemRole::Purchaser))->toThrow(AuthorizationException::class);

    expect(($this->load)($colleague)->status)->toBe(MembershipStatus::Active)
        ->and(($this->load)($colleague)->role->key)->toBe(SystemRole::Requester->value);
})->with([
    'comprador' => [SystemRole::Purchaser],
    'aprovador' => [SystemRole::Approver],
]);

it('impede o comprador de gerenciar acessos da equipe do distribuidor', function (): void {
    $buyerAdmin = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $target = ($this->load)($seller);

    expect(fn () => ($this->revoke)($buyerAdmin, $target))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->change)($buyerAdmin, $target, SystemRole::Admin))->toThrow(AuthorizationException::class);

    expect(($this->load)($seller)->status)->toBe(MembershipStatus::Active)
        ->and(($this->load)($seller)->role->key)->toBe(SystemRole::SalesRep->value);
});

it('permite à equipe com buyers.manage gerenciar compradores, sem estender a gestão da equipe', function (): void {
    $manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $purchaser = $this->tenant->addBuyerUser($this->otherCompany, SystemRole::Purchaser);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    ($this->revoke)($manager, ($this->load)($purchaser));

    expect(($this->load)($purchaser)->status)->toBe(MembershipStatus::Revoked);

    expect(fn () => ($this->revoke)($manager, ($this->load)($seller)))
        ->toThrow(AuthorizationException::class);

    expect(($this->load)($seller)->status)->toBe(MembershipStatus::Active);
});

it('impede a equipe sem buyers.manage de gerenciar compradores', function (): void {
    $warehouse = $this->tenant->addStaff(SystemRole::Warehouse);
    $purchaser = $this->tenant->addBuyerUser($this->company, SystemRole::Purchaser);
    $target = ($this->load)($purchaser);

    expect(fn () => ($this->revoke)($warehouse, $target))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->change)($warehouse, $target, SystemRole::BuyerAdmin))->toThrow(AuthorizationException::class);

    expect(($this->load)($purchaser)->status)->toBe(MembershipStatus::Active)
        ->and(($this->load)($purchaser)->role->key)->toBe(SystemRole::Purchaser->value);
});
