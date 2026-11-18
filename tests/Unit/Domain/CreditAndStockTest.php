<?php

declare(strict_types=1);

use App\Domain\Credit\CreditDecision;
use App\Domain\Credit\CreditPosition;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\StockAllocator;
use App\Domain\Inventory\WarehouseStock;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;

function position(int $limit, int $exceptions, int $committed, int $receivable, bool $blocked = false, bool $overdue = false): CreditPosition
{
    return new CreditPosition(Money::ofCents($limit), Money::ofCents($exceptions), Money::ofCents($committed), Money::ofCents($receivable), $blocked, $overdue);
}

it('calcula exposição e disponível sem dupla contagem', function (): void {
    $position = position(100_000, 20_000, 30_000, 50_000);

    expect($position->exposure()->cents)->toBe(80_000)
        ->and($position->available()->cents)->toBe(40_000)
        ->and($position->decide(Money::ofCents(40_000)))->toBe(CreditDecision::Approved)
        ->and($position->decide(Money::ofCents(40_001)))->toBe(CreditDecision::Insufficient);
});

it('recusa compra a prazo com crédito bloqueado ou títulos vencidos', function (): void {
    expect(position(100_000, 0, 0, 0, blocked: true)->decide(Money::ofCents(1)))->toBe(CreditDecision::Blocked)
        ->and(position(100_000, 0, 0, 0, overdue: true)->decide(Money::ofCents(1)))->toBe(CreditDecision::Overdue)
        ->and(CreditDecision::Insufficient->approved())->toBeFalse()
        ->and(CreditDecision::Approved->message())->toBe('Crédito disponível.')
        ->and(position(10, 0, 20, 0)->available()->cents)->toBe(-10);
});

it('aloca pelos depósitos de maior prioridade, dividindo quando necessário', function (): void {
    $stocks = [
        new WarehouseStock('dep-b', 1, Quantity::of(10), Quantity::of(2)),
        new WarehouseStock('dep-a', 5, Quantity::of(5), Quantity::of(0)),
    ];

    $allocation = (new StockAllocator)->allocate(Quantity::of(9), $stocks, false);

    expect(array_map(static fn (array $p): array => [$p['warehouseId'], $p['quantity']->toDatabase()], $allocation->parts))
        ->toBe([['dep-a', '5.000'], ['dep-b', '4.000']])
        ->and($allocation->backordered->isZero())->toBeTrue()
        ->and($allocation->reserved()->toDatabase())->toBe('9.000');
});

it('não reserva nada quando falta estoque e o produto não admite encomenda', function (): void {
    (new StockAllocator)->allocate(Quantity::of(11), [new WarehouseStock('dep', 0, Quantity::of(10), Quantity::of(0))], false, 'CAB-25');
})->throws(InsufficientStock::class, 'CAB-25');

it('registra encomenda explícita apenas para o saldo faltante', function (): void {
    $allocation = (new StockAllocator)->allocate(Quantity::of(15), [new WarehouseStock('dep', 0, Quantity::of(10), Quantity::of(4))], true);

    expect($allocation->reserved()->toDatabase())->toBe('6.000')
        ->and($allocation->backordered->toDatabase())->toBe('9.000');
});

it('trata reservado acima do físico como disponível zero', function (): void {
    expect((new WarehouseStock('dep', 0, Quantity::of(1), Quantity::of(3)))->available()->isZero())->toBeTrue();
});

it('aprova exatamente no limite e considera exceções vigentes', function (): void {
    $position = new CreditPosition(Money::ofCents(10_000), Money::ofCents(2_000), Money::ofCents(7_000), Money::ofCents(3_000), false, false);

    expect($position->exposure()->cents)->toBe(10_000)
        ->and($position->available()->cents)->toBe(2_000)
        ->and($position->decide(Money::ofCents(2_000)))->toBe(CreditDecision::Approved)
        ->and($position->decide(Money::ofCents(2_001)))->toBe(CreditDecision::Insufficient)
        ->and(CreditDecision::Insufficient->approved())->toBeFalse()
        ->and(CreditDecision::Approved->approved())->toBeTrue();
});

it('explica cada decisão de crédito', function (CreditDecision $decision, string $message): void {
    expect($decision->message())->toBe($message);
})->with([
    'aprovado' => [CreditDecision::Approved, 'Crédito disponível.'],
    'bloqueado' => [CreditDecision::Blocked, 'O crédito comercial da empresa está bloqueado.'],
    'vencidos' => [CreditDecision::Overdue, 'Há títulos vencidos acima da tolerância; regularize para comprar a prazo.'],
    'insuficiente' => [CreditDecision::Insufficient, 'Limite de crédito disponível insuficiente para este pedido.'],
]);

