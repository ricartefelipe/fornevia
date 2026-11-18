<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Histórico de limites e justificativas é dado interno: o comprador não o lê
| pelo banco, nem o da própria empresa.
*/

it('oculta do comprador o histórico de alterações de limite', function (): void {
    Fx::freezeClock();
    $tenant = TenantScenario::create();
    $own = Fx::company($tenant, 'Construtora Horizonte');
    $other = Fx::company($tenant, 'Engenharia Litoral');
    Fx::open($tenant, $own, '50.000,00');
    Fx::open($tenant, $other, '80.000,00');
    $buyer = $tenant->addBuyerUser($own);

    $seen = $tenant->actingAs($buyer, static fn (): array => [
        'changes' => DB::table('credit_limit_changes')->count(),
        'account' => DB::table('credit_accounts')->count(),
    ]);

    expect($seen)->toBe(['changes' => 0, 'account' => 0])
        ->and($tenant->system(static fn (): int => DB::table('credit_limit_changes')->count()))->toBe(2);
});
