<?php

declare(strict_types=1);

use App\Domain\Catalog\PurchaseRule;
use App\Domain\Catalog\PurchaseRuleViolation;
use App\Domain\Shared\Quantity;
use App\Services\Catalog\PurchaseQuantityAdvisor;

/*
| Conferência da quantidade digitada no portal: mensagens em português para
| cada violação da regra de compra e a quantidade válida mais próxima.
*/

beforeEach(function (): void {
    $this->advisor = new PurchaseQuantityAdvisor;
    $this->box = new PurchaseRule(Quantity::of('12'), Quantity::of('12'), 0);
    $this->unit = new PurchaseRule(Quantity::of('10'), Quantity::of('1'), 0);
    $this->weight = new PurchaseRule(Quantity::of('0,5'), Quantity::of('0,25'), 3);
});

it('aceita a quantidade que respeita mínimo, múltiplo e casas decimais', function (string $rule, string $input, string $expected): void {
    $feedback = $this->advisor->evaluate($this->{$rule}, $input, 'CX');

    expect($feedback->valid)->toBeTrue()
        ->and($feedback->messages)->toBe([])
        ->and($feedback->requested?->toDatabase())->toBe($expected)
        ->and($feedback->hasSuggestion())->toBeFalse();
})->with([
    'caixa fechada' => ['box', '24', '24.000'],
    'milhar com ponto' => ['box', '1.200', '1200.000'],
    'decimal com vírgula' => ['weight', '2,75', '2.750'],
    'decimal com ponto' => ['weight', '1.5', '1.500'],
    'espaços ao redor' => ['unit', '  15 ', '15.000'],
]);

it('explica a violação e sugere a quantidade válida mais próxima', function (string $rule, string $input, string $unit, array $violations, array $messages, ?string $suggestion): void {
    $feedback = $this->advisor->evaluate($this->{$rule}, $input, $unit);

    expect($feedback->valid)->toBeFalse()
        ->and($feedback->violations)->toBe($violations)
        ->and($feedback->messages)->toBe($messages)
        ->and($feedback->suggestion?->toDatabase())->toBe($suggestion);
})->with([
    'fora do múltiplo' => ['box', '13', 'CX', [PurchaseRuleViolation::NotMultiple], ['Este item é vendido em múltiplos de 12 CX.'], '24.000'],
    'abaixo do mínimo e fora do múltiplo' => ['box', '5', 'CX', [PurchaseRuleViolation::BelowMinimum, PurchaseRuleViolation::NotMultiple], [
        'A quantidade mínima de compra é 12 CX.',
        'Este item é vendido em múltiplos de 12 CX.',
    ], '12.000'],
    'abaixo do mínimo' => ['unit', '7', 'UN', [PurchaseRuleViolation::BelowMinimum], ['A quantidade mínima de compra é 10 UN.'], '10.000'],
    'decimais em unidade inteira' => ['unit', '12,5', 'UN', [PurchaseRuleViolation::TooManyDecimals, PurchaseRuleViolation::NotMultiple], [
        'A unidade UN não aceita casas decimais.',
    ], '13.000'],
    'mais de três casas decimais' => ['weight', '1,2345', 'KG', [PurchaseRuleViolation::TooManyDecimals, PurchaseRuleViolation::NotMultiple], [
        'A unidade KG aceita no máximo 3 casas decimais.',
        'Este item é vendido em múltiplos de 0,25 KG.',
    ], '1.250'],
    'zero' => ['box', '0', 'CX', [PurchaseRuleViolation::NotPositive], ['Informe uma quantidade maior que zero.'], '12.000'],
    'negativa' => ['box', '-24', 'CX', [PurchaseRuleViolation::NotPositive], ['Informe uma quantidade maior que zero.'], '12.000'],
]);

it('recusa entrada que não é número, sem sugerir quantidade', function (string $input): void {
    $feedback = $this->advisor->evaluate($this->box, $input, 'CX');

    expect($feedback->valid)->toBeFalse()
        ->and($feedback->requested)->toBeNull()
        ->and($feedback->messages)->toBe(['Informe a quantidade em números, por exemplo 12 ou 2,5.'])
        ->and($feedback->suggestion)->toBeNull();
})->with([
    'texto' => ['doze caixas'],
    'notação científica' => ['1e3'],
    'separadores misturados' => ['1,200.50'],
    'entrada longa demais' => [str_repeat('9', 40)],
]);

it('limita a quantidade máxima por item', function (): void {
    $feedback = $this->advisor->evaluate($this->box, '1.000.000.000', 'CX');

    expect($feedback->valid)->toBeFalse()
        ->and($feedback->messages)->toBe(['A quantidade informada excede o limite de 999.999.999 CX por item.'])
        ->and($feedback->suggestion)->toBeNull();
});

it('usa a menor quantidade válida como referência de preço', function (): void {
    expect($this->advisor->reference($this->box)->toDatabase())->toBe('12.000')
        ->and($this->advisor->reference(new PurchaseRule(Quantity::of('5'), Quantity::of('12'), 0))->toDatabase())->toBe('12.000')
        ->and($this->advisor->reference($this->weight)->toDatabase())->toBe('0.500');
});

it('trata negativo fora do intervalo como quantidade não positiva', function (): void {
    $feedback = $this->advisor->evaluate($this->box, '-1000000000000000', 'CX');

    expect($feedback->valid)->toBeFalse()
        ->and($feedback->violations)->toBe([PurchaseRuleViolation::NotPositive])
        ->and($feedback->suggestion?->toDatabase())->toBe('12.000');
});

it('lê ponto após zero inicial como decimal', function (): void {
    expect((string) $this->advisor->parse('0.500'))->toBe('0.5')
        ->and((string) $this->advisor->parse('0.25'))->toBe('0.25')
        ->and((string) $this->advisor->parse('1.000'))->toBe('1000')
        ->and($this->advisor->parse('01.000'))->toBeNull();
});
