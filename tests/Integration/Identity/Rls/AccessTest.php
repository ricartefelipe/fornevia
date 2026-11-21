<?php

declare(strict_types=1);

use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Support\Auth\Access;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\ExecutionContext;
use Illuminate\Auth\Access\AuthorizationException;

/*
| Access::can avaliado com associação e papel montados em memória
| (setRelation, sem persistência). Fica na suíte de integração porque
| Access::bindMembership chama loadMissing, que exige o resolvedor de
| conexões do Eloquent; nenhuma linha é gravada no banco.
*/

const ACCESS_TEST_TENANT = '0193b2c0-0000-7000-8000-00000000a001';
const ACCESS_TEST_USER = '0193b2c0-0000-7000-8000-00000000a002';
const ACCESS_TEST_MEMBERSHIP = '0193b2c0-0000-7000-8000-00000000a003';
const ACCESS_TEST_ROLE = '0193b2c0-0000-7000-8000-00000000a004';
const ACCESS_TEST_BUYER_COMPANY = '0193b2c0-0000-7000-8000-00000000a005';
const ACCESS_TEST_API_CLIENT = '0193b2c0-0000-7000-8000-00000000a006';

/**
 * @param  list<string>  $permissions
 */
function accessTestMembership(
    MembershipKind $kind,
    array $permissions,
    MembershipStatus $status = MembershipStatus::Active,
    int $maxDiscountBps = 0,
    string $roleKey = 'personalizado',
): Membership {
    $role = new Role;
    $role->forceFill([
        'id' => ACCESS_TEST_ROLE,
        'tenant_id' => ACCESS_TEST_TENANT,
        'key' => $roleKey,
        'name' => 'Papel de teste',
        'kind' => $kind,
        'permissions' => $permissions,
        'max_discount_bps' => $maxDiscountBps,
        'is_system' => false,
    ]);

    $membership = new Membership;
    $membership->forceFill([
        'id' => ACCESS_TEST_MEMBERSHIP,
        'tenant_id' => ACCESS_TEST_TENANT,
        'user_id' => ACCESS_TEST_USER,
        'kind' => $kind,
        'buyer_company_id' => $kind === MembershipKind::Buyer ? ACCESS_TEST_BUYER_COMPANY : null,
        'role_id' => ACCESS_TEST_ROLE,
        'status' => $status,
    ]);
    $membership->setRelation('role', $role);

    return $membership;
}

/**
 * Access com o contexto de execução correspondente à associação (ou ao
 * cliente de API) de teste, como ocorre dentro de TenantDatabase::run().
 */
function accessTestAccess(bool $integration = false): Access
{
    $context = new CurrentContext;
    $context->swap($integration
        ? ExecutionContext::integration(ACCESS_TEST_TENANT, ACCESS_TEST_API_CLIENT)
        : ExecutionContext::staff(ACCESS_TEST_USER, ACCESS_TEST_TENANT, ACCESS_TEST_MEMBERSHIP));

    return new Access($context);
}

function accessTestFor(SystemRole $role, MembershipStatus $status = MembershipStatus::Active): Access
{
    $access = accessTestAccess();
    $access->bindMembership(accessTestMembership(
        $role->kind(),
        array_map(static fn (Permission $p): string => $p->value, $role->permissions()),
        $status,
        $role->maxDiscountBps(),
        $role->value,
    ));

    return $access;
}

it('concede exatamente as permissões do papel padrão à associação ativa', function (SystemRole $role): void {
    $access = accessTestFor($role);

    foreach (Permission::cases() as $permission) {
        expect([$permission->value => $access->can($permission)])
            ->toBe([$permission->value => in_array($permission, $role->permissions(), true)]);
    }
})->with(SystemRole::cases());

it('ignora permissão de portal em associação de equipe, mesmo que o papel a liste', function (): void {
    $access = accessTestAccess();
    $access->bindMembership(accessTestMembership(MembershipKind::Staff, ['orders.view', 'portal.cart', 'portal.orders.place']));

    expect($access->can(Permission::OrdersView))->toBeTrue()
        ->and($access->can(Permission::PortalCart))->toBeFalse()
        ->and($access->can(Permission::PortalOrdersPlace))->toBeFalse();
});

it('ignora permissão de equipe em associação de comprador, mesmo que o papel a liste', function (): void {
    $access = accessTestAccess();
    $access->bindMembership(accessTestMembership(
        MembershipKind::Buyer,
        ['portal.catalog', 'members.manage', 'subscription.manage', 'pricing.approve'],
    ));

    expect($access->can(Permission::PortalCatalog))->toBeTrue()
        ->and($access->can(Permission::MembersManage))->toBeFalse()
        ->and($access->can(Permission::SubscriptionManage))->toBeFalse()
        ->and($access->can(Permission::PricingApprove))->toBeFalse();
});

it('nega todas as permissões a uma associação revogada', function (SystemRole $role): void {
    $access = accessTestFor($role, MembershipStatus::Revoked);

    foreach (Permission::cases() as $permission) {
        expect($access->can($permission))->toBeFalse();
    }

    $access->authorize($role->permissions()[0]);
})->with([SystemRole::Owner, SystemRole::BuyerAdmin])->throws(AuthorizationException::class);

it('nega acesso e não expõe dados quando nenhuma associação foi vinculada', function (): void {
    $access = accessTestAccess();

    expect($access->hasMembership())->toBeFalse()
        ->and($access->membershipOrNull())->toBeNull()
        ->and($access->isStaff())->toBeFalse()
        ->and($access->isBuyer())->toBeFalse()
        ->and($access->isIntegration())->toBeFalse()
        ->and($access->buyerCompanyId())->toBeNull()
        ->and($access->userId())->toBeNull()
        ->and($access->maxDiscountBps())->toBe(0)
        ->and($access->can(Permission::CatalogView))->toBeFalse()
        ->and($access->can(Permission::PortalCatalog))->toBeFalse()
        ->and(fn () => $access->membership())->toThrow(AuthorizationException::class, 'Nenhum contexto ativo.');
});

it('informa a permissão ausente ao recusar a autorização', function (): void {
    $access = accessTestFor(SystemRole::Admin);

    $access->authorize(Permission::MembersManage);

    $access->authorize(Permission::SubscriptionManage);
})->throws(AuthorizationException::class, 'Permissão necessária: Gerenciar assinatura do Fornevia.');

it('limita as integrações às permissões concedidas ao cliente de API', function (): void {
    $access = accessTestAccess(integration: true);
    $access->bindIntegration(ACCESS_TEST_API_CLIENT, ['orders.view', 'catalog.view']);

    expect($access->isIntegration())->toBeTrue()
        ->and($access->apiClientId())->toBe(ACCESS_TEST_API_CLIENT)
        ->and($access->hasMembership())->toBeFalse()
        ->and($access->can(Permission::OrdersView))->toBeTrue()
        ->and($access->can(Permission::CatalogView))->toBeTrue()
        ->and($access->can(Permission::OrdersManage))->toBeFalse()
        ->and($access->can(Permission::MembersManage))->toBeFalse()
        ->and($access->maxDiscountBps())->toBe(0);
});

it('descarta o vínculo anterior ao trocar entre integração e associação', function (): void {
    $access = accessTestAccess();
    $access->bindMembership(accessTestMembership(MembershipKind::Staff, ['orders.view', 'orders.manage'], maxDiscountBps: 1_500));
    $access->bindIntegration(ACCESS_TEST_API_CLIENT, []);

    expect($access->membershipOrNull())->toBeNull()
        ->and($access->isStaff())->toBeFalse()
        ->and($access->can(Permission::OrdersView))->toBeFalse()
        ->and($access->maxDiscountBps())->toBe(0);

    $access->bindMembership(accessTestMembership(MembershipKind::Staff, ['orders.view']));

    expect($access->isIntegration())->toBeFalse()
        ->and($access->apiClientId())->toBeNull()
        ->and($access->can(Permission::OrdersView))->toBeTrue()
        ->and($access->can(Permission::OrdersManage))->toBeFalse();
});

it('expõe o tipo de contexto, o usuário e a empresa compradora da associação', function (): void {
    $buyer = accessTestFor(SystemRole::Purchaser);
    $staff = accessTestFor(SystemRole::SalesRep);

    expect($buyer->isBuyer())->toBeTrue()
        ->and($buyer->isStaff())->toBeFalse()
        ->and($buyer->buyerCompanyId())->toBe(ACCESS_TEST_BUYER_COMPANY)
        ->and($buyer->userId())->toBe(ACCESS_TEST_USER)
        ->and($staff->isStaff())->toBeTrue()
        ->and($staff->isBuyer())->toBeFalse()
        ->and($staff->buyerCompanyId())->toBeNull();
});

it('usa o limite de desconto configurado no papel da associação', function (): void {
    $customized = accessTestAccess();
    $customized->bindMembership(accessTestMembership(MembershipKind::Staff, ['quotes.manage'], maxDiscountBps: 750));

    expect(accessTestFor(SystemRole::SalesManager)->maxDiscountBps())->toBe(1_500)
        ->and(accessTestFor(SystemRole::SalesRep)->maxDiscountBps())->toBe(500)
        ->and(accessTestFor(SystemRole::Purchaser)->maxDiscountBps())->toBe(0)
        ->and($customized->maxDiscountBps())->toBe(750);
});

it('compara permissões do papel de forma exata e ignora valores desconhecidos', function (): void {
    $access = accessTestAccess();
    $access->bindMembership(accessTestMembership(MembershipKind::Staff, ['ORDERS.VIEW', ' catalog.view', 'orders.*', '*']));

    foreach (Permission::forKind(MembershipKind::Staff) as $permission) {
        expect($access->can($permission))->toBeFalse();
    }
});

it('identifica papel privilegiado pelas permissões que exigem segundo fator', function (): void {
    $finance = accessTestMembership(MembershipKind::Staff, ['finance.view', 'finance.manage'])->role;
    $viewer = accessTestMembership(MembershipKind::Staff, ['orders.view', 'superusuario', 'finance.view'])->role;
    $buyerAdmin = accessTestMembership(MembershipKind::Buyer, ['portal.users', 'portal.approvals'])->role;

    expect($finance->isPrivileged())->toBeTrue()
        ->and($viewer->isPrivileged())->toBeFalse()
        ->and($buyerAdmin->isPrivileged())->toBeFalse()
        ->and($finance->grants(Permission::FinanceManage))->toBeTrue()
        ->and($finance->grants(Permission::CreditManage))->toBeFalse();
});

it('nega permissões quando o contexto ativo não é o da associação vinculada', function (): void {
    $context = new CurrentContext;
    $access = new Access($context);
    $access->bindMembership(accessTestMembership(MembershipKind::Staff, ['orders.view']));

    // Sem contexto ativo, ou durante uma elevação para outro contexto, a
    // associação vinculada não concede permissões.
    expect($access->can(Permission::OrdersView))->toBeFalse();

    $context->swap(ExecutionContext::system(ACCESS_TEST_TENANT, ACCESS_TEST_USER));
    expect($access->can(Permission::OrdersView))->toBeFalse();

    $context->swap(ExecutionContext::staff(ACCESS_TEST_USER, ACCESS_TEST_TENANT, ACCESS_TEST_MEMBERSHIP));
    expect($access->can(Permission::OrdersView))->toBeTrue();
});

it('nega permissões de integração fora do contexto do próprio cliente de API', function (): void {
    $context = new CurrentContext;
    $access = new Access($context);
    $access->bindIntegration(ACCESS_TEST_API_CLIENT, ['orders.view']);

    expect($access->can(Permission::OrdersView))->toBeFalse();

    $context->swap(ExecutionContext::integration(ACCESS_TEST_TENANT, ACCESS_TEST_API_CLIENT));
    expect($access->can(Permission::OrdersView))->toBeTrue();
});
