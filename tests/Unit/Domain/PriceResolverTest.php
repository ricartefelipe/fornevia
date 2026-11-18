<?php

declare(strict_types=1);

use App\Domain\Pricing\Exceptions\PriceNotAvailable;
use App\Domain\Pricing\PriceCandidate;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PriceTier;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;

function candidate(PriceSource $source, int $cents, string $from = '2026-01-01', ?string $until = null, int $priority = 0, string $ref = 'r', array $tiers = []): PriceCandidate
{
    return new PriceCandidate($source, $ref, Money::ofCents($cents), new DateTimeImmutable($from), $until !== null ? new DateTimeImmutable($until) : null, $priority, $tiers);
}

beforeEach(function (): void {
    $this->resolver = new PriceResolver;
    $this->today = new DateTimeImmutable('2026-11-17');
});

it('aplica a precedência negociada, cliente, grupo e padrão', function (): void {
    $all = [
        candidate(PriceSource::DefaultList, 1000, ref: 'padrao'),
        candidate(PriceSource::GroupList, 950, ref: 'grupo'),
        candidate(PriceSource::CustomerList, 900, ref: 'cliente'),
        candidate(PriceSource::Negotiated, 850, ref: 'negociada'),
    ];

    expect($this->resolver->resolve($all, Quantity::of(1), $this->today)->source)->toBe(PriceSource::Negotiated)
        ->and($this->resolver->resolve(array_slice($all, 0, 3), Quantity::of(1), $this->today)->source)->toBe(PriceSource::CustomerList)
        ->and($this->resolver->resolve(array_slice($all, 0, 2), Quantity::of(1), $this->today)->unitPrice->cents)->toBe(950)
        ->and($this->resolver->resolve(array_slice($all, 0, 1), Quantity::of(1), $this->today)->referenceId)->toBe('padrao');
});

it('ignora candidatos fora da vigência, inclusive nas bordas', function (): void {
    $expired = candidate(PriceSource::Negotiated, 500, '2026-01-01', '2026-11-16', ref: 'vencida');
    $future = candidate(PriceSource::CustomerList, 600, '2026-11-18', ref: 'futura');
    $endsToday = candidate(PriceSource::GroupList, 700, '2026-01-01', '2026-11-17', ref: 'termina-hoje');
    $default = candidate(PriceSource::DefaultList, 1000, ref: 'padrao');

    $resolved = $this->resolver->resolve([$expired, $future, $endsToday, $default], Quantity::of(1), $this->today);

    expect($resolved->referenceId)->toBe('termina-hoje');
});

it('desempata no mesmo nível por prioridade, vigência mais recente e criação', function (): void {
    $low = candidate(PriceSource::GroupList, 900, '2026-06-01', priority: 0, ref: 'b');
    $high = candidate(PriceSource::GroupList, 950, '2026-01-01', priority: 10, ref: 'c');
    expect($this->resolver->resolve([$low, $high], Quantity::of(1), $this->today)->referenceId)->toBe('c');

    $older = candidate(PriceSource::GroupList, 900, '2026-01-01', ref: 'a');
    $recent = candidate(PriceSource::GroupList, 910, '2026-06-01', ref: 'z');
    expect($this->resolver->resolve([$older, $recent], Quantity::of(1), $this->today)->referenceId)->toBe('z');

    $first = candidate(PriceSource::GroupList, 900, '2026-06-01', ref: '0193-a');
    $second = candidate(PriceSource::GroupList, 800, '2026-06-01', ref: '0193-b');
    expect($this->resolver->resolve([$second, $first], Quantity::of(1), $this->today)->referenceId)->toBe('0193-a');
});

it('aplica a faixa atingida a todas as unidades da linha', function (): void {
    $tiers = [new PriceTier(Quantity::of(10), Money::ofCents(900)), new PriceTier(Quantity::of(50), Money::ofCents(800))];
    $list = candidate(PriceSource::DefaultList, 1000, tiers: $tiers);

    $nine = $this->resolver->resolve([$list], Quantity::of(9), $this->today);
    $ten = $this->resolver->resolve([$list], Quantity::of(10), $this->today);
    $sixty = $this->resolver->resolve([$list], Quantity::of(60), $this->today);

    expect([$nine->unitPrice->cents, $nine->tier])->toBe([1000, null])
        ->and($ten->unitPrice->cents)->toBe(900)
        ->and($sixty->unitPrice->cents)->toBe(800)
        ->and($sixty->snapshot())->toMatchArray(['source' => 'default_list', 'unit_price_cents' => 800, 'tier_min_quantity' => '50.000']);
});

it('falha quando não há preço vigente', function (): void {
    $this->resolver->resolve([candidate(PriceSource::DefaultList, 1000, '2027-01-01')], Quantity::of(1), $this->today);
})->throws(PriceNotAvailable::class);
