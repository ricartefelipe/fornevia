<?php

declare(strict_types=1);

use App\Domain\Catalog\PurchaseRule;
use App\Domain\Catalog\PurchaseRuleViolation as V;
use App\Domain\Shared\Quantity;

it('recusa quantidade incompatível com caixa fechada de 12 unidades', function (): void {
    $rule = new PurchaseRule(Quantity::of(12), Quantity::of(12), 0);

    expect($rule->violations(Quantity::of(13)))->toBe([V::NotMultiple])
        ->and($rule->violations(Quantity::of(6)))->toBe([V::BelowMinimum, V::NotMultiple])
        ->and($rule->accepts(Quantity::of(24)))->toBeTrue()
        ->and($rule->nearestValid(Quantity::of(13))->toDatabase())->toBe('24.000')
        ->and($rule->nearestValid(Quantity::of(1))->toDatabase())->toBe('12.000');
});

it('controla casas decimais permitidas pela unidade', function (): void {
    $meters = new PurchaseRule(Quantity::of('0.5'), Quantity::of('0.5'), 1);

    expect($meters->accepts(Quantity::of('2.5')))->toBeTrue()
        ->and($meters->violations(Quantity::of('2.25')))->toBe([V::TooManyDecimals, V::NotMultiple])
        ->and($meters->violations(Quantity::zero()))->toBe([V::NotPositive])
        ->and($meters->violations(Quantity::of(-1)))->toBe([V::NotPositive]);
});

it('sugere mínimo quando o múltiplo não alcança o mínimo', function (): void {
    $rule = new PurchaseRule(Quantity::of(10), Quantity::of(4), 0);

    expect($rule->nearestValid(Quantity::of(1))->toDatabase())->toBe('12.000');
});

it('rejeita configuração inválida', function (): void {
    new PurchaseRule(Quantity::zero(), Quantity::of(1), 0);
})->throws(InvalidArgumentException::class);

it('rejeita casas decimais fora do intervalo', function (): void {
    new PurchaseRule(Quantity::of(1), Quantity::of(1), 4);
})->throws(InvalidArgumentException::class);
