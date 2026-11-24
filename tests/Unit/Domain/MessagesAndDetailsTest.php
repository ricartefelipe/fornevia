<?php

declare(strict_types=1);

use App\Domain\Inventory\StockAllocator;
use App\Domain\Inventory\WarehouseStock;
use App\Domain\Quotes\QuoteTerms;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;
use Carbon\CarbonImmutable;

/*
| Mensagens e detalhes das recusas do domínio, que chegam ao usuário e à
| API (campo apontado, valores informados), e o desempate da alocação.
*/

function domainRefusal(Closure $call): array
{
    try {
        $call();
    } catch (BusinessRuleViolation $e) {
        return [$e->getMessage(), $e->details()];
    }

    throw new RuntimeException('Nenhuma recusa.');
}

function warehouse(string $id, int $priority, string $onHand): WarehouseStock
{
    return new WarehouseStock($id, $priority, Quantity::of($onHand), Quantity::zero());
}

it('desempata depósitos de mesma prioridade pelo identificador', function (): void {
    $allocation = (new StockAllocator)->allocate(Quantity::of('5'), [warehouse('c', 5, '10'), warehouse('a', 5, '10'), warehouse('b', 9, '1')], false);

    expect(array_map(static fn (array $p): string => $p['warehouseId'].':'.$p['quantity']->toDatabase(), $allocation->parts))->toBe(['b:1.000', 'a:4.000']);
});

it('explica a falta de estoque com e sem o SKU', function (): void {
    $allocator = new StockAllocator;

    expect(domainRefusal(fn () => $allocator->allocate(Quantity::of('5'), [warehouse('a', 1, '2')], false, 'DJ-32A')))
        ->toBe(['Estoque insuficiente para o SKU DJ-32A.', ['sku' => 'DJ-32A', 'required' => '5.000', 'missing' => '3.000']])
        ->and(domainRefusal(fn () => $allocator->allocate(Quantity::of('1'), [], false)))
        ->toBe(['Estoque insuficiente.', ['sku' => '', 'required' => '1.000', 'missing' => '1.000']]);
});

it('aponta o campo de cada recusa da validade e da reserva da proposta', function (): void {
    $terms = new QuoteTerms('America/Sao_Paulo', 30, 10);
    $now = CarbonImmutable::parse('2026-11-24T12:00:00Z');
    $valid = $terms->validUntil(' 2026-12-10 ', $now);

    expect($valid->toIso8601String())->toBe('2026-12-11T02:59:59+00:00')
        ->and(domainRefusal(fn () => $terms->validUntil('2026-11-23', $now)))->toBe(['A validade não pode terminar antes de hoje.', ['field' => 'valid_until']])
        ->and(domainRefusal(fn () => $terms->validUntil('2026-12-25', $now)))->toBe(['A validade pode ser de até 30 dias.', ['field' => 'valid_until']])
        ->and(domainRefusal(fn () => $terms->validUntil('2026-02-30', $now)))->toBe(['Informe a validade da proposta.', ['field' => 'valid_until']])
        // Dia 29 de fevereiro só existe em ano bissexto: o ano é conferido.
        ->and(domainRefusal(fn () => $terms->validUntil('2027-02-29', $now)))->toBe(['Informe a validade da proposta.', ['field' => 'valid_until']])
        ->and(domainRefusal(fn () => $terms->holdUntil('2026-11-23', $valid, $now)))->toBe(['A reserva não pode terminar antes de hoje.', ['field' => 'stock_hold_until']])
        ->and(domainRefusal(fn () => $terms->holdUntil('2026-12-11', $valid, $now)))->toBe(['A reserva não pode ir além da validade da proposta.', ['field' => 'stock_hold_until']])
        ->and(domainRefusal(fn () => $terms->holdUntil('2026-12-05', $valid, $now)))->toBe(['A reserva pode ser de até 10 dias.', ['field' => 'stock_hold_until']])
        ->and(domainRefusal(fn () => $terms->holdUntil('amanhã', $valid, $now)))->toBe(['Informe até quando reservar o estoque.', ['field' => 'stock_hold_until']]);
});
