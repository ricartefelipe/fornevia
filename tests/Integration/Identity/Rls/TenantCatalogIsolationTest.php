<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Buyers\CustomerGroup;
use App\Services\Platform\PlanLimits;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ExecutionContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| Isolamento por tenant de roles, customer_groups e tenant_usage_counters
| (somente a política tenant_isolation; sem filtro por empresa compradora).
*/

it('mostra a cada tenant apenas os próprios papéis padrão, inclusive no contexto de comprador', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $buyer = $aurora->addBuyerUser($aurora->addBuyerCompany());

    $auroraRoles = $aurora->system(fn () => DB::table('roles')->pluck('key', 'id')->all());
    $borealRoles = $boreal->system(fn () => DB::table('roles')->pluck('key', 'id')->all());
    $asBuyer = $aurora->actingAs($buyer, fn (): array => DB::table('roles')->pluck('id')->all());
    $withoutContext = DB::transaction(fn (): int => DB::table('roles')->count());

    $keys = array_map(static fn (SystemRole $r): string => $r->value, SystemRole::cases());

    expect(array_values($auroraRoles))->toEqualCanonicalizing($keys)
        ->and(array_values($borealRoles))->toEqualCanonicalizing($keys)
        ->and(array_intersect(array_keys($auroraRoles), array_keys($borealRoles)))->toBe([])
        ->and($asBuyer)->toEqualCanonicalizing(array_keys($auroraRoles))
        ->and($withoutContext)->toBe(0);
});

it('impede que um tenant altere ou remova papéis de outro tenant', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $auroraViewer = $aurora->role(SystemRole::Viewer);

    $affected = $boreal->system(fn (): array => [
        DB::table('roles')->where('id', $auroraViewer->id)->update([
            'permissions' => json_encode(['subscription.manage', 'members.manage']),
            'max_discount_bps' => 10_000,
        ]),
        DB::table('roles')->where('key', SystemRole::Viewer->value)->whereNot('tenant_id', $boreal->organization->id)->delete(),
    ]);

    $stored = $aurora->role(SystemRole::Viewer);

    expect($affected)->toBe([0, 0])
        ->and($stored->permissions)->toBe($auroraViewer->permissions)
        ->and($stored->max_discount_bps)->toBe(0);
});

it('recusa na política WITH CHECK papel gravado com o tenant de outro contexto', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();

    $boreal->system(fn () => DB::table('roles')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $aurora->organization->id,
        'key' => 'auditor_externo',
        'name' => 'Auditor externo',
        'kind' => 'staff',
        'permissions' => json_encode(['members.manage']),
        'created_at' => now(),
        'updated_at' => now(),
    ]));
})->throws(QueryException::class, 'new row violates row-level security policy for table "roles"');

it('isola grupos de clientes por tenant, inclusive com o mesmo código', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();

    $auroraGroup = $aurora->system(fn () => CustomerGroup::query()->create([
        'code' => 'VAREJO', 'name' => 'Varejo regional', 'description' => 'Mercados de bairro',
    ]));
    $boreal->system(fn () => CustomerGroup::query()->create(['code' => 'VAREJO', 'name' => 'Varejo alimentar']));
    $buyer = $aurora->addBuyerUser($aurora->addBuyerCompany(['customer_group_id' => $auroraGroup->id]));

    $auroraNames = $aurora->system(fn (): array => DB::table('customer_groups')->pluck('name')->all());
    $borealUpdate = $boreal->system(fn (): int => DB::table('customer_groups')->where('code', 'VAREJO')->update(['name' => 'Varejo renomeado']));
    $borealLookup = $boreal->system(fn (): int => DB::table('customer_groups')->where('id', $auroraGroup->id)->count());
    $asBuyer = $aurora->actingAs($buyer, fn (): array => DB::table('customer_groups')->pluck('id')->all());

    expect($auroraNames)->toBe(['Varejo regional'])
        ->and($borealUpdate)->toBe(1)
        ->and($borealLookup)->toBe(0)
        ->and($asBuyer)->toBe([$auroraGroup->id])
        ->and($aurora->system(fn () => DB::table('customer_groups')->where('id', $auroraGroup->id)->value('name')))->toBe('Varejo regional')
        ->and($boreal->system(fn () => DB::table('customer_groups')->value('name')))->toBe('Varejo renomeado');

    expect(fn () => $boreal->system(fn () => DB::table('customer_groups')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $aurora->organization->id,
        'code' => 'ATACADO',
        'name' => 'Atacado',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'row-level security');
});

it('mantém o contador mensal de pedidos de cada tenant isolado', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();

    $aurora->system(function (): void {
        $limits = app(PlanLimits::class);
        $limits->consumeOrderQuota();
        $limits->consumeOrderQuota();
        $limits->consumeOrderQuota();
    });
    $boreal->system(fn () => app(PlanLimits::class)->consumeOrderQuota());

    $auroraRows = $aurora->system(fn (): array => DB::table('tenant_usage_counters')->get(['tenant_id', 'orders_confirmed'])->map(fn (object $r): array => (array) $r)->all());
    $borealRows = $boreal->system(fn (): array => DB::table('tenant_usage_counters')->get(['tenant_id', 'orders_confirmed'])->map(fn (object $r): array => (array) $r)->all());
    $borealBulkUpdate = $boreal->system(fn (): int => DB::table('tenant_usage_counters')->update(['orders_confirmed' => 0]));
    $userContext = inContext(ExecutionContext::user($aurora->owner->id), fn (): int => DB::table('tenant_usage_counters')->count());
    $withoutContext = DB::transaction(fn (): int => DB::table('tenant_usage_counters')->count());

    expect($auroraRows)->toBe([['tenant_id' => $aurora->organization->id, 'orders_confirmed' => 3]])
        ->and($borealRows)->toBe([['tenant_id' => $boreal->organization->id, 'orders_confirmed' => 1]])
        ->and($borealBulkUpdate)->toBe(1)
        ->and($aurora->system(fn () => DB::table('tenant_usage_counters')->value('orders_confirmed')))->toBe(3)
        ->and($userContext)->toBe(0)
        ->and($withoutContext)->toBe(0);

    expect(fn () => $boreal->system(fn () => DB::table('tenant_usage_counters')->insert([
        'tenant_id' => $aurora->organization->id,
        'period' => app(Clock::class)->today()->startOfMonth()->toDateString(),
        'orders_confirmed' => 0,
    ])))->toThrow(QueryException::class, 'new row violates row-level security policy for table "tenant_usage_counters"');
});

it('não deixa um tenant consumir a cota de pedidos de outro tenant', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $period = app(Clock::class)->today()->startOfMonth()->toDateString();

    $aurora->system(fn () => app(PlanLimits::class)->consumeOrderQuota());

    $affected = $boreal->system(fn (): int => DB::update(
        'update tenant_usage_counters set orders_confirmed = orders_confirmed + 1 where tenant_id = ? and period = ?',
        [$aurora->organization->id, $period],
    ));

    expect($affected)->toBe(0)
        ->and($aurora->system(fn () => DB::table('tenant_usage_counters')->value('orders_confirmed')))->toBe(1);
});
