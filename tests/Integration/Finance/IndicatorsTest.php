<?php

declare(strict_types=1);

use App\Actions\Ordering\CancelOrderItems;
use App\Enums\Identity\SystemRole;
use App\Services\Finance\IndicatorsQuery;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Indicadores (RF-102): receita líquida do não cancelado, sem pedidos
| cancelados por inteiro.
*/

it('calcula o ticket médio sem os pedidos cancelados e pela receita do não cancelado', function (): void {
    $scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $manager = $scenario->tenant->addStaff(SystemRole::SalesManager);
    $kept = Fx::place($scenario, $scenario->buyerWithCart(quantity: '10', sameCompany: true));
    $partial = Fx::place($scenario, $scenario->buyerWithCart(quantity: '4', sameCompany: true));
    $cancelled = Fx::place($scenario, $scenario->buyerWithCart(quantity: '2', sameCompany: true));
    $item = $scenario->system(fn () => App\Models\Ordering\OrderItem::query()->where('order_id', $partial->id)->firstOrFail());
    $scenario->tenant->actingAs($manager, fn () => app(CancelOrderItems::class)->handle($partial, [$item->id => App\Domain\Shared\Quantity::of('2')], 'Cliente reduziu o pedido'));
    $scenario->tenant->actingAs($manager, fn () => app(CancelOrderItems::class)->handle($cancelled, null, 'Cliente desistiu'));

    $data = $scenario->system(fn (): array => app(IndicatorsQuery::class)->for(now()->subDay()->toImmutable(), now()->addDay()->toImmutable(), now()->toImmutable()));

    // (1.000 + 200) ÷ 2 pedidos.
    expect($data['orders'])->toBe(2)
        ->and($data['revenue']->cents)->toBe(120_000)
        ->and($data['average_ticket']?->cents)->toBe(60_000)
        ->and($data['conversion'])->toBeNull()
        ->and($data['delinquency'])->toBeNull()
        ->and($kept->number)->not->toBe($cancelled->number);
});
