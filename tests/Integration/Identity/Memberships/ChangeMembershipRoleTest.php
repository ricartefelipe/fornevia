<?php

declare(strict_types=1);

use App\Actions\Identity\ChangeMembershipRole;
use App\Domain\Identity\Exceptions\LastOwnerRequired;
use App\Domain\Identity\Exceptions\RoleKindMismatch;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();

    $this->change = fn (Membership $actor, Membership $target, string $roleId): Membership => $this->tenant->actingAs(
        $actor,
        static fn (): Membership => app(ChangeMembershipRole::class)->handle(Membership::query()->findOrFail($target->id), $roleId),
    );

    $this->roleKeyOf = fn (Membership $membership): string => $this->tenant->system(
        static fn (): string => Membership::query()->with('role')->findOrFail($membership->id)->role->key,
    );

    $this->roleChangeLogs = fn (Membership $membership) => $this->tenant->system(
        static fn () => AuditLog::query()
            ->where('action', 'membership.role_changed')
            ->where('subject_id', $membership->id)
            ->get(),
    );
});

it('altera o papel e registra na auditoria o papel anterior e o novo', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $manager = $this->tenant->role(SystemRole::SalesManager);

    $result = ($this->change)($admin, $seller, $manager->id);

    expect($result->role_id)->toBe($manager->id)
        ->and($result->role->key)->toBe(SystemRole::SalesManager->value)
        ->and(($this->roleKeyOf)($seller))->toBe(SystemRole::SalesManager->value);

    $logs = ($this->roleChangeLogs)($seller);

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->subject_type)->toBe('membership')
        ->and($logs->first()->actor_user_id)->toBe($admin->user_id)
        ->and($logs->first()->actor_membership_id)->toBe($admin->id)
        ->and($logs->first()->data)->toEqual([
            'from' => SystemRole::SalesRep->value,
            'to' => SystemRole::SalesManager->value,
        ]);
});

it('não altera nem audita quando o papel escolhido é o atual', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    $result = ($this->change)($admin, $seller, $this->tenant->role(SystemRole::SalesRep)->id);

    expect($result->role->key)->toBe(SystemRole::SalesRep->value)
        ->and(($this->roleChangeLogs)($seller))->toBeEmpty();
});

it('rejeita papel de outro tipo de acesso', function (string $targetKind, SystemRole $currentRole, SystemRole $chosenRole): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $target = $targetKind === 'staff'
        ? $this->tenant->addStaff($currentRole)
        : $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), $currentRole);

    expect(fn () => ($this->change)($admin, $target, $this->tenant->role($chosenRole)->id))
        ->toThrow(RoleKindMismatch::class, 'O papel escolhido não pertence a este tipo de acesso.');

    expect(($this->roleKeyOf)($target))->toBe($currentRole->value)
        ->and(($this->roleChangeLogs)($target))->toBeEmpty();
})->with([
    'papel de comprador para a equipe' => ['staff', SystemRole::SalesRep, SystemRole::BuyerAdmin],
    'papel da equipe para comprador' => ['buyer', SystemRole::Purchaser, SystemRole::Admin],
]);

it('não aceita papel pertencente a outro tenant', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $foreignRole = TenantScenario::create()->role(SystemRole::SalesManager);

    expect(fn () => ($this->change)($admin, $seller, $foreignRole->id))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->roleKeyOf)($seller))->toBe(SystemRole::SalesRep->value)
        ->and(($this->roleChangeLogs)($seller))->toBeEmpty();
});

it('impede rebaixar o último proprietário ativo', function (): void {
    $owner = $this->tenant->ownerMembership;

    expect(fn () => ($this->change)($owner, $owner, $this->tenant->role(SystemRole::Admin)->id))
        ->toThrow(LastOwnerRequired::class);

    expect(($this->roleKeyOf)($owner))->toBe(SystemRole::Owner->value)
        ->and(($this->roleChangeLogs)($owner))->toBeEmpty();
});

it('permite ao proprietário rebaixar outro proprietário enquanto restar um ativo', function (): void {
    $partner = $this->tenant->addStaff(SystemRole::Owner);

    ($this->change)($this->tenant->ownerMembership, $partner, $this->tenant->role(SystemRole::Admin)->id);

    expect(($this->roleKeyOf)($partner))->toBe(SystemRole::Admin->value)
        ->and(($this->roleChangeLogs)($partner)->first()?->data)->toEqual([
            'from' => SystemRole::Owner->value,
            'to' => SystemRole::Admin->value,
        ]);
});

it('somente proprietário concede o papel de proprietário', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $ownerRole = $this->tenant->role(SystemRole::Owner);

    expect(fn () => ($this->change)($admin, $manager, $ownerRole->id))
        ->toThrow(AuthorizationException::class, 'Somente proprietários');

    expect(($this->roleKeyOf)($manager))->toBe(SystemRole::SalesManager->value)
        ->and(($this->roleChangeLogs)($manager))->toBeEmpty();

    ($this->change)($this->tenant->ownerMembership, $manager, $ownerRole->id);

    expect(($this->roleKeyOf)($manager))->toBe(SystemRole::Owner->value)
        ->and(($this->roleChangeLogs)($manager)->first()?->data)->toEqual([
            'from' => SystemRole::SalesManager->value,
            'to' => SystemRole::Owner->value,
        ]);
});

it('impede que administrador retire o papel de um proprietário', function (): void {
    $admin = $this->tenant->addStaff(SystemRole::Admin);
    $partner = $this->tenant->addStaff(SystemRole::Owner);

    expect(fn () => ($this->change)($admin, $partner, $this->tenant->role(SystemRole::Viewer)->id))
        ->toThrow(AuthorizationException::class, 'Somente proprietários');

    expect(($this->roleKeyOf)($partner))->toBe(SystemRole::Owner->value)
        ->and(($this->roleChangeLogs)($partner))->toBeEmpty();
});

it('exige members.manage para alterar papéis da equipe', function (): void {
    $manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->change)($manager, $seller, $this->tenant->role(SystemRole::SalesManager)->id))
        ->toThrow(AuthorizationException::class);

    expect(($this->roleKeyOf)($seller))->toBe(SystemRole::SalesRep->value)
        ->and(($this->roleChangeLogs)($seller))->toBeEmpty();
});
