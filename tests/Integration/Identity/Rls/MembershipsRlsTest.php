<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Support\Tenancy\ExecutionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| Políticas de RLS de memberships, exercitadas com SQL direto no papel da
| aplicação (fornevia_app), sem depender do TenantScope do Eloquent:
| - tenant_isolation (permissiva, todas as operações);
| - own_memberships (permissiva, SELECT): o próprio usuário;
| - buyer_isolation (restritiva): comprador vê a própria empresa e o
|   representante responsável; grava somente na própria empresa.
*/

/**
 * @return list<string>
 */
function membershipsRlsVisibleIds(): array
{
    return DB::table('memberships')->pluck('id')->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function membershipsRlsRow(string $tenantId, array $overrides): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'user_id' => User::factory()->create()->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

it('no contexto de usuário expõe somente as associações do próprio usuário, em todos os tenants', function (): void {
    $aurora = TenantScenario::create(name: 'Distribuidora Aurora');
    $boreal = TenantScenario::create(name: 'Distribuidora Boreal');
    $user = User::factory()->create(['name' => 'Marina Tavares']);

    $staffInAurora = $aurora->addStaff(SystemRole::SalesRep, $user);
    $company = $boreal->addBuyerCompany(['trade_name' => 'Mercado Serra Azul']);
    $buyerInBoreal = $boreal->addBuyerUser($company, SystemRole::Purchaser, $user);

    $colleague = $boreal->addBuyerUser($company);
    $otherStaff = $aurora->addStaff(SystemRole::Admin);

    $visible = inContext(ExecutionContext::user($user->id), fn (): array => membershipsRlsVisibleIds());
    $lookupOthers = inContext(ExecutionContext::user($user->id), fn (): int => DB::table('memberships')
        ->whereIn('id', [$colleague->id, $otherStaff->id, $aurora->ownerMembership->id, $boreal->ownerMembership->id])
        ->count());
    $ownerView = inContext(ExecutionContext::user($aurora->owner->id), fn (): array => membershipsRlsVisibleIds());

    expect($visible)->toEqualCanonicalizing([$staffInAurora->id, $buyerInBoreal->id])
        ->and($lookupOthers)->toBe(0)
        ->and($ownerView)->toBe([$aurora->ownerMembership->id]);
});

it('no contexto de usuário não expõe nenhuma outra tabela dos tenants em que o usuário atua', function (): void {
    $aurora = TenantScenario::create();
    $company = $aurora->addBuyerCompany();
    $aurora->addBuyerUser($company);

    $counts = inContext(ExecutionContext::user($aurora->owner->id), fn (): array => collect([
        'roles', 'customer_groups', 'buyer_companies', 'branches', 'invitations', 'audit_logs', 'tenant_usage_counters',
    ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all());

    expect($counts)->toBe([
        'roles' => 0,
        'customer_groups' => 0,
        'buyer_companies' => 0,
        'branches' => 0,
        'invitations' => 0,
        'audit_logs' => 0,
        'tenant_usage_counters' => 0,
    ]);
});

it('no contexto de usuário não permite que o usuário crie uma associação para si', function (): void {
    $aurora = TenantScenario::create();
    $intruder = User::factory()->create();
    $ownerRoleId = $aurora->role(SystemRole::Owner)->id;

    inContext(ExecutionContext::user($intruder->id), fn () => DB::table('memberships')->insert(
        membershipsRlsRow($aurora->organization->id, [
            'user_id' => $intruder->id,
            'kind' => 'staff',
            'role_id' => $ownerRoleId,
        ]),
    ));
})->throws(QueryException::class, 'row-level security');

it('no contexto de usuário não permite reativar nem excluir a própria associação revogada', function (): void {
    $aurora = TenantScenario::create();
    $user = User::factory()->create();
    $membership = $aurora->addStaff(SystemRole::SalesManager, $user);

    $aurora->system(fn () => DB::table('memberships')->where('id', $membership->id)->update([
        'status' => 'revoked',
        'revoked_at' => now(),
        'revoke_reason' => 'Desligamento',
    ]));

    [$updated, $deleted] = inContext(ExecutionContext::user($user->id), fn (): array => [
        DB::table('memberships')->where('id', $membership->id)->update(['status' => 'active', 'revoked_at' => null]),
        DB::table('memberships')->where('id', $membership->id)->delete(),
    ]);

    $stored = $aurora->system(fn () => DB::table('memberships')->where('id', $membership->id)->first(['status', 'revoked_at']));

    expect($updated)->toBe(0)
        ->and($deleted)->toBe(0)
        ->and($stored->status)->toBe('revoked')
        ->and($stored->revoked_at)->not->toBeNull();
});

it('mostra ao comprador os colegas da própria empresa e o representante responsável', function (): void {
    $tenant = TenantScenario::create();
    $responsibleRep = $tenant->addStaff(SystemRole::SalesRep);
    $otherRep = $tenant->addStaff(SystemRole::SalesRep);
    $manager = $tenant->addStaff(SystemRole::SalesManager);

    $ownCompany = $tenant->addBuyerCompany(['sales_rep_membership_id' => $responsibleRep->id]);
    $otherCompany = $tenant->addBuyerCompany(['sales_rep_membership_id' => $otherRep->id]);

    $buyer = $tenant->addBuyerUser($ownCompany, SystemRole::Purchaser);
    $approver = $tenant->addBuyerUser($ownCompany, SystemRole::Approver);
    $otherBuyer = $tenant->addBuyerUser($otherCompany, SystemRole::BuyerAdmin);

    [$sql, $eloquent, $lookup] = $tenant->actingAs($buyer, fn (): array => [
        membershipsRlsVisibleIds(),
        Membership::query()->pluck('id')->all(),
        DB::table('memberships')
            ->whereIn('id', [$otherRep->id, $manager->id, $tenant->ownerMembership->id, $otherBuyer->id])
            ->count(),
    ]);

    expect($sql)->toEqualCanonicalizing([$buyer->id, $approver->id, $responsibleRep->id])
        ->and($eloquent)->toEqualCanonicalizing($sql)
        ->and($lookup)->toBe(0);
});

it('mostra ao comprador de empresa sem representante apenas os colegas da empresa', function (): void {
    $tenant = TenantScenario::create();
    $tenant->addStaff(SystemRole::SalesRep);
    $withRep = $tenant->addBuyerCompany(['sales_rep_membership_id' => $tenant->addStaff(SystemRole::SalesRep)->id]);
    $tenant->addBuyerUser($withRep);

    $company = $tenant->addBuyerCompany();
    $requester = $tenant->addBuyerUser($company, SystemRole::Requester);
    $buyerAdmin = $tenant->addBuyerUser($company, SystemRole::BuyerAdmin);

    $visible = $tenant->actingAs($requester, fn (): array => membershipsRlsVisibleIds());

    expect($visible)->toEqualCanonicalizing([$requester->id, $buyerAdmin->id]);
});

it('acompanha a troca do representante responsável pela empresa compradora', function (): void {
    $tenant = TenantScenario::create();
    $previousRep = $tenant->addStaff(SystemRole::SalesRep);
    $newRep = $tenant->addStaff(SystemRole::SalesRep);
    $company = $tenant->addBuyerCompany(['sales_rep_membership_id' => $previousRep->id]);
    $buyer = $tenant->addBuyerUser($company);

    $before = $tenant->actingAs($buyer, fn (): array => membershipsRlsVisibleIds());

    $tenant->system(fn () => BuyerCompany::query()->whereKey($company->id)->update(['sales_rep_membership_id' => $newRep->id]));

    $after = $tenant->actingAs($buyer, fn (): array => membershipsRlsVisibleIds());

    expect($before)->toEqualCanonicalizing([$buyer->id, $previousRep->id])
        ->and($after)->toEqualCanonicalizing([$buyer->id, $newRep->id]);
});

it('mostra à equipe todas as associações do tenant e nenhuma de outro tenant', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $rep = $aurora->addStaff(SystemRole::SalesRep);
    $buyer = $aurora->addBuyerUser($aurora->addBuyerCompany());
    $borealBuyer = $boreal->addBuyerUser($boreal->addBuyerCompany());

    $visible = $aurora->actingAs($rep, fn (): array => membershipsRlsVisibleIds());

    expect($visible)->toEqualCanonicalizing([$aurora->ownerMembership->id, $rep->id, $buyer->id])
        ->and($visible)->not->toContain($boreal->ownerMembership->id, $borealBuyer->id);
});

it('impede na política WITH CHECK que o comprador grave associação fora da própria empresa', function (string $target): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $otherCompany = $tenant->addBuyerCompany();
    $buyerAdmin = $tenant->addBuyerUser($company, SystemRole::BuyerAdmin);
    $purchaserRoleId = $tenant->role(SystemRole::Purchaser)->id;
    $ownerRoleId = $tenant->role(SystemRole::Owner)->id;
    $tenantId = $tenant->organization->id;

    // Controle: na própria empresa a inclusão é aceita pela política.
    $tenant->actingAs($buyerAdmin, fn () => DB::table('memberships')->insert(membershipsRlsRow($tenantId, [
        'kind' => 'buyer',
        'buyer_company_id' => $company->id,
        'role_id' => $purchaserRoleId,
    ])));

    $row = match ($target) {
        'outra empresa' => ['kind' => 'buyer', 'buyer_company_id' => $otherCompany->id, 'role_id' => $purchaserRoleId],
        'equipe do distribuidor' => ['kind' => 'staff', 'buyer_company_id' => null, 'role_id' => $ownerRoleId],
    };

    expect(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('memberships')->insert(membershipsRlsRow($tenantId, $row))))
        ->toThrow(QueryException::class, 'new row violates row-level security policy');

    $stored = $tenant->system(fn (): array => [
        DB::table('memberships')->where('buyer_company_id', $otherCompany->id)->count(),
        DB::table('memberships')->where('kind', 'staff')->count(),
    ]);

    expect($stored)->toBe([0, 1]);
})->with(['outra empresa', 'equipe do distribuidor']);

it('impede o comprador de mover colega para outra empresa e de alterar a associação do representante', function (): void {
    $tenant = TenantScenario::create();
    $rep = $tenant->addStaff(SystemRole::SalesRep);
    $company = $tenant->addBuyerCompany(['sales_rep_membership_id' => $rep->id]);
    $otherCompany = $tenant->addBuyerCompany();
    $buyerAdmin = $tenant->addBuyerUser($company, SystemRole::BuyerAdmin);
    $colleague = $tenant->addBuyerUser($company, SystemRole::Purchaser);

    expect(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('memberships')
        ->where('id', $colleague->id)
        ->update(['buyer_company_id' => $otherCompany->id])))
        ->toThrow(QueryException::class, 'row-level security');

    expect(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('memberships')
        ->where('id', $rep->id)
        ->update(['status' => 'revoked', 'revoked_at' => now(), 'revoke_reason' => 'Troca indevida'])))
        ->toThrow(QueryException::class, 'row-level security');

    $stored = $tenant->system(fn (): array => [
        DB::table('memberships')->where('id', $colleague->id)->value('buyer_company_id'),
        DB::table('memberships')->where('id', $rep->id)->value('status'),
    ]);

    expect($stored)->toBe([$company->id, 'active']);
});

it('em contexto de tenant não expõe as associações que o próprio usuário mantém em outro tenant', function (): void {
    $aurora = TenantScenario::create(name: 'Distribuidora Aurora');
    $boreal = TenantScenario::create(name: 'Distribuidora Boreal');
    $user = User::factory()->create();

    $buyerInAurora = $aurora->addBuyerUser($aurora->addBuyerCompany(), SystemRole::Purchaser, $user);
    $staffInBoreal = $boreal->addStaff(SystemRole::Finance, $user);

    $foreignRows = static fn (string $tenantId): int => DB::table('memberships')->where('tenant_id', '<>', $tenantId)->count();

    // A visibilidade por usuário existe para a seleção de contexto (contexto
    // User); com tenant ativo, vale o isolamento por tenant (ADR-003).
    expect([
        'comprador na Aurora' => $aurora->actingAs($buyerInAurora, fn (): int => $foreignRows($aurora->organization->id)),
        'equipe na Boreal' => $boreal->actingAs($staffInBoreal, fn (): int => $foreignRows($boreal->organization->id)),
        'sistema da Boreal' => $boreal->system(fn (): int => $foreignRows($boreal->organization->id)),
        'sistema da Boreal com usuário atuante' => inContext(
            ExecutionContext::system($boreal->organization->id, $user->id),
            fn (): int => $foreignRows($boreal->organization->id),
        ),
    ])->toBe([
        'comprador na Aurora' => 0,
        'equipe na Boreal' => 0,
        'sistema da Boreal' => 0,
        'sistema da Boreal com usuário atuante' => 0,
    ]);
});
