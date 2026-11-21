<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/*
| Dentro do tenant, o contexto de comprador lê, mas não altera, tabelas
| mantidas pela equipe do distribuidor, mesmo com SQL direto.
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->company = $this->tenant->addBuyerCompany(['status' => 'blocked']);
    $this->buyer = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
});

it('permite ao comprador ler papéis, filiais e a própria empresa', function (): void {
    $visible = $this->tenant->actingAs($this->buyer, fn (): array => [
        'roles' => DB::table('roles')->count(),
        'branches' => DB::table('branches')->count(),
        'company' => DB::table('buyer_companies')->value('id'),
    ]);

    expect($visible['roles'])->toBe(11)
        ->and($visible['branches'])->toBe(1)
        ->and($visible['company'])->toBe($this->company->id);
});

it('ignora alterações e exclusões do comprador em tabelas da equipe', function (string $table, array $changes): void {
    $affected = $this->tenant->actingAs($this->buyer, fn (): array => [
        'update' => DB::table($table)->update($changes),
        'delete' => DB::table($table)->delete(),
    ]);

    $unchanged = $this->tenant->system(fn (): int => DB::table($table)->count());

    expect($affected)->toBe(['update' => 0, 'delete' => 0])
        ->and($unchanged)->toBeGreaterThan(0);
})->with([
    'papéis com novas permissões' => ['roles', ['permissions' => '["members.manage"]']],
    'filiais' => ['branches', ['name' => 'Alterada']],
    'cadastro da própria empresa' => ['buyer_companies', ['status' => 'active']],
]);

it('recusa inclusões do comprador em tabelas da equipe', function (): void {
    $this->tenant->actingAs($this->buyer, fn () => DB::table('customer_groups')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $this->tenant->organization->id,
        'code' => 'VIP',
        'name' => 'Clientes especiais',
        'created_at' => now(),
        'updated_at' => now(),
    ]));
})->throws(QueryException::class, 'row-level security');

it('mantém a escrita da equipe nas mesmas tabelas', function (): void {
    $affected = $this->tenant->actingAs($this->tenant->ownerMembership, fn (): int => DB::table('buyer_companies')
        ->where('id', $this->company->id)
        ->update(['status' => 'active']));

    expect($affected)->toBe(1);
});
