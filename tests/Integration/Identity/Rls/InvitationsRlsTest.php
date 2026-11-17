<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Invitation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| invitations: isolamento por tenant e política restritiva "nullable" por
| empresa compradora (convites sem empresa, isto é, de equipe, não são
| filtrados por empresa).
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invitationsRlsRow(TenantScenario $tenant, SystemRole $role, ?BuyerCompany $company, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->organization->id,
        'email' => Str::lower(Str::random(10)).'@example.com',
        'kind' => $role->kind()->value,
        'buyer_company_id' => $company?->id,
        'role_id' => $tenant->role($role)->id,
        'token_hash' => Invitation::hashToken(Str::random(64)),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $tenant->owner->id,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $row
 */
function invitationsRlsSeed(TenantScenario $tenant, array $row): string
{
    $tenant->system(fn () => DB::table('invitations')->insert($row));

    return (string) $row['id'];
}

it('restringe o comprador aos convites da própria empresa, sem expor convites da equipe nem de outra empresa', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $own = $aurora->addBuyerCompany();
    $other = $aurora->addBuyerCompany();
    $buyerAdmin = $aurora->addBuyerUser($own, SystemRole::BuyerAdmin);

    $staffInvite = invitationsRlsSeed($aurora, invitationsRlsRow($aurora, SystemRole::Warehouse, null));
    $ownInvite = invitationsRlsSeed($aurora, invitationsRlsRow($aurora, SystemRole::Requester, $own));
    $otherInvite = invitationsRlsSeed($aurora, invitationsRlsRow($aurora, SystemRole::Purchaser, $other));
    $borealInvite = invitationsRlsSeed($boreal, invitationsRlsRow($boreal, SystemRole::Viewer, null));

    $asBuyer = $aurora->actingAs($buyerAdmin, fn (): array => DB::table('invitations')->pluck('id')->all());
    $buyerLookup = $aurora->actingAs($buyerAdmin, fn (): ?Invitation => Invitation::query()->find($otherInvite));
    $asStaff = $aurora->actingAs($aurora->ownerMembership, fn (): array => DB::table('invitations')->pluck('id')->all());
    $asBoreal = $boreal->actingAs($boreal->ownerMembership, fn (): array => DB::table('invitations')->pluck('id')->all());
    $withoutContext = DB::transaction(fn (): int => DB::table('invitations')->count());

    expect($asBuyer)->toEqualCanonicalizing([$ownInvite])
        ->and($buyerLookup)->toBeNull()
        ->and($asStaff)->toEqualCanonicalizing([$staffInvite, $ownInvite, $otherInvite])
        ->and($asBoreal)->toBe([$borealInvite])
        ->and($withoutContext)->toBe(0);
});

it('impede na política WITH CHECK que o comprador emita convite para outra empresa', function (): void {
    $tenant = TenantScenario::create();
    $own = $tenant->addBuyerCompany();
    $other = $tenant->addBuyerCompany();
    $buyerAdmin = $tenant->addBuyerUser($own, SystemRole::BuyerAdmin);

    $ownRow = invitationsRlsRow($tenant, SystemRole::Purchaser, $own);
    $otherRow = invitationsRlsRow($tenant, SystemRole::Purchaser, $other);

    // Controle: convite para a própria empresa é aceito pela política.
    $tenant->actingAs($buyerAdmin, fn () => DB::table('invitations')->insert($ownRow));

    expect(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('invitations')->insert($otherRow)))
        ->toThrow(QueryException::class, 'new row violates row-level security policy');

    expect($tenant->system(fn (): array => [
        DB::table('invitations')->where('buyer_company_id', $own->id)->count(),
        DB::table('invitations')->where('buyer_company_id', $other->id)->count(),
    ]))->toBe([1, 0]);
});

it('impede que outro tenant altere ou revogue convites do distribuidor', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $invite = invitationsRlsSeed($aurora, invitationsRlsRow($aurora, SystemRole::SalesRep, null));

    $affected = $boreal->system(fn (): array => [
        DB::table('invitations')->where('id', $invite)->update(['revoked_at' => now()]),
        DB::table('invitations')->where('id', $invite)->delete(),
    ]);

    expect($affected)->toBe([0, 0])
        ->and($aurora->system(fn () => DB::table('invitations')->where('id', $invite)->value('revoked_at')))->toBeNull();
});

it('não permite ao contexto de comprador criar ou modificar convites da equipe do distribuidor', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $buyerAdmin = $tenant->addBuyerUser($company, SystemRole::BuyerAdmin);
    $ownerRoleId = $tenant->role(SystemRole::Owner)->id;
    $viewerRoleId = $tenant->role(SystemRole::Viewer)->id;
    $pending = invitationsRlsSeed($tenant, invitationsRlsRow($tenant, SystemRole::Viewer, null, ['email' => 'novo.colaborador@example.com']));
    $another = invitationsRlsSeed($tenant, invitationsRlsRow($tenant, SystemRole::Finance, null, ['email' => 'analista.financeiro@example.com']));
    $staffRow = invitationsRlsRow($tenant, SystemRole::Owner, null, ['email' => 'socio.externo@example.com']);

    // Ambas as formas de recusa preservam os dados: erro de política (WITH
    // CHECK) ou nenhuma linha visível para o comprador (USING).
    $attempt = static function (Closure $statement): string {
        try {
            $statement();

            return 'aceito';
        } catch (QueryException) {
            return 'recusado';
        }
    };

    $insertion = $attempt(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('invitations')->insert($staffRow)));
    $attempt(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('invitations')
        ->where('id', $pending)
        ->update(['role_id' => $ownerRoleId])));
    $attempt(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('invitations')->where('id', $another)->delete()));

    $stored = $tenant->system(fn (): array => [
        DB::table('invitations')->where('email', 'socio.externo@example.com')->count(),
        DB::table('invitations')->where('id', $pending)->value('role_id'),
        DB::table('invitations')->where('id', $another)->exists(),
    ]);

    expect([
        'convite de equipe emitido pelo comprador' => $insertion,
        'convites de proprietário gravados' => $stored[0],
        'papel do convite pendente da equipe' => $stored[1] === $viewerRoleId ? 'inalterado' : 'alterado para proprietário',
        'convite pendente da equipe preservado' => $stored[2],
    ])->toBe([
        'convite de equipe emitido pelo comprador' => 'recusado',
        'convites de proprietário gravados' => 0,
        'papel do convite pendente da equipe' => 'inalterado',
        'convite pendente da equipe preservado' => true,
    ]);
});
