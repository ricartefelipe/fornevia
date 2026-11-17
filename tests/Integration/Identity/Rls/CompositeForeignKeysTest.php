<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\Branch;
use App\Models\Identity\Invitation;
use App\Models\Identity\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| Chaves estrangeiras compostas (tenant_id, coluna) -> (tenant_id, id):
| o banco recusa referência a registro de outro tenant, mesmo no contexto
| de sistema do tenant que grava (que enxerga todas as linhas dele).
*/

/**
 * @return array{headquarters: string, purchaser: string, salesRep: string}
 */
function compositeFkReferences(TenantScenario $tenant): array
{
    return [
        'headquarters' => $tenant->system(fn (): string => (string) Branch::query()->where('is_headquarters', true)->value('id')),
        'purchaser' => $tenant->role(SystemRole::Purchaser)->id,
        'salesRep' => $tenant->role(SystemRole::SalesRep)->id,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function compositeFkMembership(string $tenantId, array $overrides): array
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

it('recusa associação do tenant A que referencia registro do tenant B', function (string $reference, string $constraint): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $own = compositeFkReferences($aurora);
    $foreign = compositeFkReferences($boreal);
    $ownCompany = $aurora->addBuyerCompany()->id;
    $foreignCompany = $boreal->addBuyerCompany()->id;

    [$valid, $invalid] = match ($reference) {
        'papel' => [
            ['kind' => 'staff', 'role_id' => $own['salesRep']],
            ['kind' => 'staff', 'role_id' => $foreign['salesRep']],
        ],
        'filial' => [
            ['kind' => 'staff', 'role_id' => $own['salesRep'], 'branch_id' => $own['headquarters']],
            ['kind' => 'staff', 'role_id' => $own['salesRep'], 'branch_id' => $foreign['headquarters']],
        ],
        'empresa compradora' => [
            ['kind' => 'buyer', 'role_id' => $own['purchaser'], 'buyer_company_id' => $ownCompany],
            ['kind' => 'buyer', 'role_id' => $own['purchaser'], 'buyer_company_id' => $foreignCompany],
        ],
    };

    $tenantId = $aurora->organization->id;

    // Controle: a mesma gravação com referências do próprio tenant é aceita.
    $aurora->system(fn () => DB::table('memberships')->insert(compositeFkMembership($tenantId, $valid)));

    try {
        $aurora->system(fn () => DB::table('memberships')->insert(compositeFkMembership($tenantId, $invalid)));
        $this->fail('A referência a registro de outro tenant deveria ter sido recusada.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23503')
            ->and($e->getMessage())->toContain($constraint);
    }

    expect($aurora->system(fn (): int => DB::table('memberships')->count()))->toBe(2);
})->with([
    'papel' => ['papel', 'memberships_tenant_id_role_id_foreign'],
    'filial' => ['filial', 'memberships_tenant_id_branch_id_foreign'],
    'empresa compradora' => ['empresa compradora', 'memberships_tenant_id_buyer_company_id_foreign'],
]);

it('recusa representante responsável de outro tenant na empresa compradora', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $foreignRep = $boreal->addStaff(SystemRole::SalesRep);
    $company = $aurora->addBuyerCompany();

    try {
        $aurora->system(fn () => DB::table('buyer_companies')
            ->where('id', $company->id)
            ->update(['sales_rep_membership_id' => $foreignRep->id]));
        $this->fail('O representante de outro tenant deveria ter sido recusado.');
    } catch (QueryException $e) {
        expect($e->getCode())->toBe('23503')
            ->and($e->getMessage())->toContain('buyer_companies_sales_rep_fk');
    }

    expect($aurora->system(fn () => DB::table('buyer_companies')->where('id', $company->id)->value('sales_rep_membership_id')))->toBeNull();
});

it('recusa convite do tenant A com papel ou empresa compradora do tenant B', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $foreignRole = $boreal->role(SystemRole::BuyerAdmin)->id;
    $ownRole = $aurora->role(SystemRole::BuyerAdmin)->id;
    $foreignCompany = $boreal->addBuyerCompany()->id;
    $ownCompany = $aurora->addBuyerCompany()->id;

    $row = fn (string $roleId, string $companyId): array => [
        'id' => (string) Str::uuid7(),
        'tenant_id' => $aurora->organization->id,
        'email' => 'compras.'.Str::lower(Str::random(6)).'@example.com',
        'kind' => 'buyer',
        'buyer_company_id' => $companyId,
        'role_id' => $roleId,
        'token_hash' => Invitation::hashToken(Str::random(64)),
        'expires_at' => now()->addDays(7),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => $aurora->system(fn () => DB::table('invitations')->insert($row($foreignRole, $ownCompany))))
        ->toThrow(QueryException::class, 'invitations_tenant_id_role_id_foreign')
        ->and(fn () => $aurora->system(fn () => DB::table('invitations')->insert($row($ownRole, $foreignCompany))))
        ->toThrow(QueryException::class, 'invitations_tenant_id_buyer_company_id_foreign');

    expect($aurora->system(fn (): int => DB::table('invitations')->count()))->toBe(0);
});

it('não permite contornar a chave composta gravando com o tenant do registro referenciado', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $foreignRole = $boreal->role(SystemRole::Owner)->id;
    $row = compositeFkMembership($boreal->organization->id, ['kind' => 'staff', 'role_id' => $foreignRole]);

    expect(fn () => $aurora->system(fn () => DB::table('memberships')->insert($row)))
        ->toThrow(QueryException::class, 'new row violates row-level security policy for table "memberships"');

    expect($boreal->system(fn (): array => DB::table('memberships')->pluck('id')->all()))
        ->toBe([$boreal->ownerMembership->id]);
});
