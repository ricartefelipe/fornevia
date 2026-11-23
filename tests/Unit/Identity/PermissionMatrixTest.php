<?php

declare(strict_types=1);

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;

/**
 * @param  list<Permission>  $permissions
 * @return list<string>
 */
function permissionMatrixValues(array $permissions): array
{
    $values = array_map(static fn (Permission $p): string => $p->value, $permissions);
    sort($values);

    return $values;
}

it('separa o catálogo de permissões entre equipe e portal sem sobreposição', function (): void {
    $staff = Permission::forKind(MembershipKind::Staff);
    $buyer = Permission::forKind(MembershipKind::Buyer);

    expect(count($staff) + count($buyer))->toBe(count(Permission::cases()))
        ->and(array_intersect(permissionMatrixValues($staff), permissionMatrixValues($buyer)))->toBe([])
        ->and($buyer)->toHaveCount(9)
        ->and(array_filter($buyer, static fn (Permission $p): bool => ! str_starts_with($p->value, 'portal.')))->toBe([])
        ->and(array_filter($staff, static fn (Permission $p): bool => str_starts_with($p->value, 'portal.')))->toBe([]);
});

it('classifica como permissão de comprador exatamente as do prefixo portal', function (Permission $permission): void {
    expect($permission->isBuyerPermission())->toBe(str_starts_with($permission->value, 'portal.'));
})->with(Permission::cases());

it('concede ao proprietário todas as permissões de equipe e nenhuma do portal', function (): void {
    expect(permissionMatrixValues(SystemRole::Owner->permissions()))
        ->toBe(permissionMatrixValues(Permission::forKind(MembershipKind::Staff)));
});

it('concede ao administrador tudo da equipe exceto a gestão da assinatura do Fornevia', function (): void {
    $admin = SystemRole::Admin->permissions();
    $expected = array_values(array_filter(
        Permission::forKind(MembershipKind::Staff),
        static fn (Permission $p): bool => $p !== Permission::SubscriptionManage,
    ));

    expect(permissionMatrixValues($admin))->toBe(permissionMatrixValues($expected))
        ->and($admin)->not->toContain(Permission::SubscriptionManage)
        ->and($admin)->toContain(Permission::MembersManage, Permission::OrganizationManage, Permission::FinanceManage);
});

it('reserva a gestão da assinatura do Fornevia ao proprietário', function (): void {
    $holders = array_values(array_filter(
        SystemRole::cases(),
        static fn (SystemRole $r): bool => in_array(Permission::SubscriptionManage, $r->permissions(), true),
    ));

    expect($holders)->toBe([SystemRole::Owner]);
});

it('mantém papéis de comprador apenas com permissões do portal', function (SystemRole $role): void {
    $permissions = $role->permissions();

    expect($role->kind())->toBe(MembershipKind::Buyer)
        ->and($permissions)->not->toBeEmpty()
        ->and($permissions)->toContain(Permission::PortalCatalog)
        ->and(array_filter($permissions, static fn (Permission $p): bool => ! $p->isBuyerPermission()))->toBe([]);
})->with(SystemRole::forKind(MembershipKind::Buyer));

it('nunca concede permissão do portal a papéis de equipe', function (SystemRole $role): void {
    $permissions = $role->permissions();

    expect($role->kind())->toBe(MembershipKind::Staff)
        ->and($permissions)->not->toBeEmpty()
        ->and(array_filter($permissions, static fn (Permission $p): bool => $p->isBuyerPermission()))->toBe([]);
})->with(SystemRole::forKind(MembershipKind::Staff));

it('não repete permissões dentro de um mesmo papel', function (SystemRole $role): void {
    $values = array_map(static fn (Permission $p): string => $p->value, $role->permissions());

    expect($values)->toBe(array_values(array_unique($values)));
})->with(SystemRole::cases());

it('divide os papéis padrão em sete de equipe e quatro de comprador', function (): void {
    expect(SystemRole::forKind(MembershipKind::Staff))->toBe([
        SystemRole::Owner, SystemRole::Admin, SystemRole::SalesManager, SystemRole::SalesRep,
        SystemRole::Warehouse, SystemRole::Finance, SystemRole::Viewer,
    ])->and(SystemRole::forKind(MembershipKind::Buyer))->toBe([
        SystemRole::BuyerAdmin, SystemRole::Purchaser, SystemRole::Requester, SystemRole::Approver,
    ]);
});

it('concede ao administrador da empresa compradora todas as permissões do portal', function (): void {
    expect(permissionMatrixValues(SystemRole::BuyerAdmin->permissions()))
        ->toBe(permissionMatrixValues(Permission::forKind(MembershipKind::Buyer)))
        ->and(SystemRole::Purchaser->permissions())->not->toContain(Permission::PortalUsers, Permission::PortalApprovals)
        ->and(SystemRole::Requester->permissions())->not->toContain(Permission::PortalOrdersPlace)
        ->and(SystemRole::Approver->permissions())->toContain(Permission::PortalApprovals)
        ->and(SystemRole::Approver->permissions())->not->toContain(Permission::PortalOrdersPlace, Permission::PortalCart);
});

it('restringe o papel de consulta a permissões de visualização', function (): void {
    $permissions = SystemRole::Viewer->permissions();

    expect($permissions)->not->toBeEmpty()
        ->and(array_filter($permissions, static fn (Permission $p): bool => ! str_ends_with($p->value, '.view')))->toBe([])
        ->and(array_filter($permissions, static fn (Permission $p): bool => $p->isPrivileged()))->toBe([]);
});

it('define o limite de desconto de cada papel em pontos-base', function (SystemRole $role, int $expected): void {
    expect($role->maxDiscountBps())->toBe($expected)
        ->and($role->maxDiscountBps())->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(10_000);
})->with([
    'proprietário' => [SystemRole::Owner, 10_000],
    'administrador' => [SystemRole::Admin, 10_000],
    'gerente comercial' => [SystemRole::SalesManager, 1_500],
    'representante comercial' => [SystemRole::SalesRep, 500],
    'estoque e expedição' => [SystemRole::Warehouse, 0],
    'financeiro e crédito' => [SystemRole::Finance, 0],
    'consulta' => [SystemRole::Viewer, 0],
    'administrador da empresa' => [SystemRole::BuyerAdmin, 0],
    'comprador' => [SystemRole::Purchaser, 0],
    'solicitante' => [SystemRole::Requester, 0],
    'aprovador' => [SystemRole::Approver, 0],
]);

it('só concede desconto a papéis de equipe que elaboram propostas, em ordem hierárquica', function (): void {
    $withDiscount = array_values(array_filter(SystemRole::cases(), static fn (SystemRole $r): bool => $r->maxDiscountBps() > 0));

    expect($withDiscount)->toBe([SystemRole::Owner, SystemRole::Admin, SystemRole::SalesManager, SystemRole::SalesRep])
        ->and(SystemRole::SalesRep->maxDiscountBps())->toBeLessThan(SystemRole::SalesManager->maxDiscountBps())
        ->and(SystemRole::SalesManager->maxDiscountBps())->toBeLessThan(SystemRole::Admin->maxDiscountBps());

    foreach ($withDiscount as $role) {
        expect($role->kind())->toBe(MembershipKind::Staff)
            ->and($role->permissions())->toContain(Permission::QuotesManage);
    }
});

it('exige segundo fator exatamente para as permissões sensíveis da equipe', function (): void {
    $privileged = array_values(array_filter(Permission::cases(), static fn (Permission $p): bool => $p->isPrivileged()));

    expect(permissionMatrixValues($privileged))->toBe(permissionMatrixValues([
        Permission::OrganizationManage,
        Permission::MembersManage,
        Permission::CreditManage,
        Permission::FinanceManage,
        Permission::IntegrationsManage,
        Permission::SubscriptionManage,
    ]))->and(array_filter($privileged, static fn (Permission $p): bool => $p->isBuyerPermission()))->toBe([]);
});

it('não atribui permissão sensível a papéis operacionais sem gestão', function (SystemRole $role): void {
    expect(array_filter($role->permissions(), static fn (Permission $p): bool => $p->isPrivileged()))->toBe([]);
})->with([
    SystemRole::SalesManager, SystemRole::SalesRep, SystemRole::Warehouse, SystemRole::Viewer,
    SystemRole::BuyerAdmin, SystemRole::Purchaser, SystemRole::Requester, SystemRole::Approver,
]);

it('fornece rótulo distinto e não vazio para cada permissão e papel', function (): void {
    $permissionLabels = array_map(static fn (Permission $p): string => $p->label(), Permission::cases());
    $roleLabels = array_map(static fn (SystemRole $r): string => $r->label(), SystemRole::cases());

    expect(array_filter($permissionLabels, static fn (string $l): bool => trim($l) === ''))->toBe([])
        ->and(array_unique($permissionLabels))->toHaveCount(count(Permission::cases()))
        ->and(array_unique($roleLabels))->toHaveCount(count(SystemRole::cases()));
});
