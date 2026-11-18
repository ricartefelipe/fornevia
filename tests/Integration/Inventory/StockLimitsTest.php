<?php

declare(strict_types=1);

use App\Actions\Inventory\ReceiveStock;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Quantity;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Saldo resultante acima de numeric(18,3): recusa como regra de negócio,
| sem erro de banco, e sem alterar o saldo.
*/

it('recusa entrada que levaria o saldo além do limite suportado', function (): void {
    $tenant = TenantScenario::create();
    $warehouse = Fx::warehouse($tenant);
    $sku = Fx::sku($tenant);
    Fx::receive($tenant, $warehouse, $sku, '999999999999999');

    expect(fn () => $tenant->actingAs($tenant->ownerMembership, static fn () => app(ReceiveStock::class)->handle($warehouse, $sku, Quantity::of('1'))))
        ->toThrow(AmountOutOfRange::class);

    expect(Fx::level($tenant, $warehouse, $sku)['on_hand'] ?? null)->toBe('999999999999999.000');
});
