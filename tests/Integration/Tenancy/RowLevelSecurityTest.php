<?php

declare(strict_types=1);

use App\Models\Identity\Branch;
use App\Models\Identity\Organization;
use App\Support\Tenancy\Exceptions\CrossTenantReference;
use App\Support\Tenancy\Exceptions\TenantContextConflict;
use App\Support\Tenancy\Exceptions\TenantContextMissing;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->tenantA = Organization::factory()->create();
    $this->tenantB = Organization::factory()->create();

    asTenant($this->tenantA, fn () => Branch::factory()->create(['code' => 'A01']));
    asTenant($this->tenantB, fn () => Branch::factory()->create(['code' => 'B01']));
});

it('conecta a aplicação com papel sem superuser, sem BYPASSRLS e sem propriedade das tabelas', function (): void {
    $role = DB::selectOne('select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->name)->toBe(config('database.roles.app'))
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();

    $owned = DB::selectOne("select count(*) as total from pg_tables where schemaname = 'public' and tableowner = current_user");
    expect($owned->total)->toBe(0);
});

it('não retorna linhas protegidas sem contexto ativo, mesmo com SQL direto', function (): void {
    $rows = DB::transaction(fn () => DB::select('select id from branches'));

    expect($rows)->toBe([]);
});

it('isola as linhas de cada tenant pela política de RLS', function (): void {
    $codesA = asTenant($this->tenantA, fn () => DB::table('branches')->pluck('code')->all());
    $codesB = asTenant($this->tenantB, fn () => DB::table('branches')->pluck('code')->all());

    expect($codesA)->toBe(['A01'])->and($codesB)->toBe(['B01']);
});

it('bloqueia na política WITH CHECK a gravação com tenant de outro contexto', function (): void {
    asTenant($this->tenantA, fn () => DB::table('branches')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenantB->id,
        'code' => 'X99',
        'name' => 'Indevida',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
})->throws(QueryException::class, 'row-level security');

it('impede alterar linhas de outro tenant por UPDATE direto', function (): void {
    $affected = asTenant($this->tenantA, fn () => DB::table('branches')->where('code', 'B01')->update(['name' => 'Alterada']));

    expect($affected)->toBe(0);
    expect(asTenant($this->tenantB, fn () => Branch::query()->value('name')))->not->toBe('Alterada');
});

it('aplica o contexto apenas dentro da transação e limpa a conexão ao final', function (): void {
    $database = app(TenantDatabase::class);

    $inside = asTenant($this->tenantA, fn () => $database->activeDatabaseSettings());
    $after = DB::transaction(fn () => $database->activeDatabaseSettings());

    expect($inside['tenant_id'])->toBe($this->tenantA->id)
        ->and($inside['context_kind'])->toBe('system')
        ->and($after['tenant_id'])->toBe('')
        ->and($after['context_kind'])->toBe('');
});

it('limpa o contexto da conexão quando o trabalho falha', function (): void {
    $database = app(TenantDatabase::class);

    try {
        asTenant($this->tenantA, function (): never {
            Branch::factory()->create(['code' => 'A02']);
            throw new RuntimeException('falha simulada');
        });
    } catch (RuntimeException) {
    }

    $after = DB::transaction(fn () => $database->activeDatabaseSettings());

    expect($after['tenant_id'])->toBe('')
        ->and(asTenant($this->tenantA, fn () => Branch::query()->pluck('code')->all()))->toBe(['A01']);
});

it('rejeita trocar de tenant dentro de uma transação aberta', function (): void {
    asTenant($this->tenantA, fn () => asTenant($this->tenantB, fn () => null));
})->throws(TenantContextConflict::class);

it('reutiliza a transação quando o mesmo contexto é solicitado de forma aninhada', function (): void {
    $codes = asTenant($this->tenantA, fn () => asTenant($this->tenantA, fn () => Branch::query()->pluck('code')->all()));

    expect($codes)->toBe(['A01']);
});

it('falha de forma explícita ao consultar modelo do tenant sem contexto', function (): void {
    Branch::query()->get();
})->throws(TenantContextMissing::class);

it('preenche o tenant a partir do contexto e recusa tenant divergente no modelo', function (): void {
    $branch = asTenant($this->tenantA, fn () => Branch::factory()->create(['code' => 'A03']));
    expect($branch->tenant_id)->toBe($this->tenantA->id);

    asTenant($this->tenantA, function (): void {
        $foreign = new Branch(['code' => 'Z01', 'name' => 'Outra']);
        $foreign->setAttribute('tenant_id', $this->tenantB->id);
        $foreign->save();
    });
})->throws(CrossTenantReference::class);

it('impede alterar o tenant de um registro existente', function (): void {
    asTenant($this->tenantA, function (): void {
        $branch = Branch::query()->firstOrFail();
        $branch->setAttribute('tenant_id', $this->tenantB->id);
        $branch->save();
    });
})->throws(CrossTenantReference::class);

it('restringe linhas de comprador pela política restritiva quando o contexto é de comprador', function (): void {
    $buyerA = (string) Str::uuid7();
    $buyerB = (string) Str::uuid7();
    $user = (string) Str::uuid7();
    $membership = (string) Str::uuid7();

    // A verificação usa as funções diretamente: o contexto de comprador só
    // enxerga a própria empresa; staff e sistema enxergam todas.
    $buyerView = inContext(
        ExecutionContext::buyer($user, $this->tenantA->id, $buyerA, $membership),
        fn () => DB::selectOne('select app_sees_all_buyers() as all_buyers, app_current_buyer() as buyer'),
    );
    $systemView = asTenant($this->tenantA, fn () => DB::selectOne('select app_sees_all_buyers() as all_buyers, app_current_buyer() as buyer'));

    expect($buyerView->all_buyers)->toBeFalse()
        ->and($buyerView->buyer)->toBe($buyerA)
        ->and($buyerView->buyer)->not->toBe($buyerB)
        ->and($systemView->all_buyers)->toBeTrue()
        ->and($systemView->buyer)->toBeNull();
});
