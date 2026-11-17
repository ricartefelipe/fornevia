<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Support\Tenancy\ExecutionContext;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| buyer_companies: isolamento por tenant e política restritiva pela própria
| coluna id (o comprador enxerga apenas o registro da sua empresa).
*/

it('mostra ao comprador somente o cadastro da própria empresa', function (): void {
    $tenant = TenantScenario::create();
    $own = $tenant->addBuyerCompany(['trade_name' => 'Supermercado Vale Verde']);
    $other = $tenant->addBuyerCompany(['trade_name' => 'Atacado Ponte Alta']);
    $tenant->addBuyerCompany(['trade_name' => 'Rede Farma Litoral']);
    $buyer = $tenant->addBuyerUser($own, SystemRole::Approver);

    [$sql, $eloquent, $byId] = $tenant->actingAs($buyer, fn (): array => [
        DB::table('buyer_companies')->pluck('trade_name')->all(),
        BuyerCompany::query()->pluck('id')->all(),
        BuyerCompany::query()->find($other->id),
    ]);

    expect($sql)->toBe(['Supermercado Vale Verde'])
        ->and($eloquent)->toBe([$own->id])
        ->and($byId)->toBeNull();
});

it('mostra à equipe e ao sistema todas as empresas do tenant e nenhuma de outro tenant', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $first = $aurora->addBuyerCompany();
    $second = $aurora->addBuyerCompany();
    $foreign = $boreal->addBuyerCompany();
    $warehouse = $aurora->addStaff(SystemRole::Warehouse);

    $asStaff = $aurora->actingAs($warehouse, fn (): array => DB::table('buyer_companies')->pluck('id')->all());
    $asSystem = $aurora->system(fn (): array => DB::table('buyer_companies')->pluck('id')->all());
    $fromBoreal = $boreal->actingAs($boreal->ownerMembership, fn (): array => DB::table('buyer_companies')->pluck('id')->all());
    $borealLookup = $boreal->system(fn (): int => DB::table('buyer_companies')->whereIn('id', [$first->id, $second->id])->count());

    expect($asStaff)->toEqualCanonicalizing([$first->id, $second->id])
        ->and($asSystem)->toEqualCanonicalizing([$first->id, $second->id])
        ->and($fromBoreal)->toBe([$foreign->id])
        ->and($borealLookup)->toBe(0);
});

it('mantém isolado o mesmo CNPJ cadastrado por distribuidores diferentes', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    $document = BrazilianDocuments::cnpj();

    $aurora->addBuyerCompany(['document' => $document, 'trade_name' => 'Padaria Estrela', 'notes' => 'Prazo de 28 dias']);
    $boreal->addBuyerCompany(['document' => $document, 'trade_name' => 'Padaria Estrela Matriz', 'notes' => 'Pagamento antecipado']);

    $auroraView = $aurora->system(fn (): array => DB::table('buyer_companies')->where('document', $document)->pluck('notes')->all());
    $borealView = $boreal->system(fn (): array => DB::table('buyer_companies')->where('document', $document)->pluck('notes')->all());
    $withoutContext = DB::transaction(fn (): int => DB::table('buyer_companies')->where('document', $document)->count());

    expect($auroraView)->toBe(['Prazo de 28 dias'])
        ->and($borealView)->toBe(['Pagamento antecipado'])
        ->and($withoutContext)->toBe(0);
});

it('não permite ao comprador alterar outra empresa nem cadastrar empresas', function (): void {
    $tenant = TenantScenario::create();
    $own = $tenant->addBuyerCompany();
    $other = $tenant->addBuyerCompany(['email' => 'compras@pontealta.example.com']);
    $buyerAdmin = $tenant->addBuyerUser($own, SystemRole::BuyerAdmin);

    $affected = $tenant->actingAs($buyerAdmin, fn (): int => DB::table('buyer_companies')
        ->where('id', $other->id)
        ->update(['email' => 'desvio@example.com']));

    expect($affected)->toBe(0)
        ->and($tenant->system(fn () => DB::table('buyer_companies')->where('id', $other->id)->value('email')))
        ->toBe('compras@pontealta.example.com');

    expect(fn () => $tenant->actingAs($buyerAdmin, fn () => DB::table('buyer_companies')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenant->organization->id,
        'legal_name' => 'Comercial Nova Fronteira Ltda',
        'trade_name' => 'Nova Fronteira',
        'document' => BrazilianDocuments::cnpj(),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class, 'row-level security');
});

it('não expõe empresas compradoras sem contexto ou apenas no contexto de usuário', function (): void {
    $tenant = TenantScenario::create();
    $company = $tenant->addBuyerCompany();
    $buyer = $tenant->addBuyerUser($company);

    $withoutContext = DB::transaction(fn (): int => DB::table('buyer_companies')->count());
    $userContext = inContext(ExecutionContext::user($buyer->user_id), fn (): int => DB::table('buyer_companies')->count());

    expect($withoutContext)->toBe(0)->and($userContext)->toBe(0);
});
