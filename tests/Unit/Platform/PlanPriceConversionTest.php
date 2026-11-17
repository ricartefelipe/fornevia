<?php

declare(strict_types=1);

use App\Enums\Identity\OrganizationStatus;
use App\Livewire\Platform\PlansIndex;

/*
 * Preços dos planos são digitados em reais ("2490,90") e persistidos em
 * centavos. A conversão é feita com aritmética inteira: valores como 0,29
 * ou 19,99 produziriam 28 e 1998 centavos se passassem por ponto flutuante.
 */

it('converte valores em reais para centavos com aritmética inteira', function (string $input, int $expected): void {
    $cents = PlansIndex::toCents($input);

    expect($cents)->toBeInt()->toBe($expected);
})->with([
    'valor inteiro sem vírgula' => ['890', 89_000],
    'duas casas decimais' => ['2490,90', 249_090],
    'uma casa decimal equivale a dezenas de centavos' => ['0,5', 50],
    'zero à esquerda nos centavos' => ['10,05', 1_005],
    'um centavo' => ['0,01', 1],
    'zero' => ['0,00', 0],
    '0,29 (vira 28 com ponto flutuante)' => ['0,29', 29],
    '19,99 (vira 1998 com ponto flutuante)' => ['19,99', 1_999],
    '4,35 (vira 434 com ponto flutuante)' => ['4,35', 435],
    'maior valor aceito pela validação do formulário' => ['999999999,99', 99_999_999_999],
]);

it('formata centavos em reais com vírgula e sempre duas casas decimais', function (int $cents, string $expected): void {
    expect(PlansIndex::fromCents($cents))->toBe($expected);
})->with([
    'zero' => [0, '0,00'],
    'um centavo' => [1, '0,01'],
    'dezenas de centavos' => [50, '0,50'],
    'zero à esquerda nos centavos' => [1_005, '10,05'],
    'valor redondo' => [89_000, '890,00'],
    'mensalidade de referência' => [249_090, '2490,90'],
    'maior valor aceito pela validação do formulário' => [99_999_999_999, '999999999,99'],
]);

it('preserva o valor em centavos na ida e volta entre formulário e banco', function (): void {
    $mismatches = [];

    foreach ([...range(0, 10_000), 123_456_789, 99_999_999_999] as $cents) {
        if (PlansIndex::toCents(PlansIndex::fromCents($cents)) !== $cents) {
            $mismatches[] = $cents;
        }
    }

    expect($mismatches)->toBe([]);
});

it('permite acesso apenas a organizações em avaliação, ativas ou com pagamento pendente', function (OrganizationStatus $status, bool $allowed): void {
    expect($status->allowsAccess())->toBe($allowed);
})->with([
    'em avaliação' => [OrganizationStatus::Trial, true],
    'ativa' => [OrganizationStatus::Active, true],
    'pagamento pendente' => [OrganizationStatus::PastDue, true],
    'suspensa' => [OrganizationStatus::Suspended, false],
    'cancelada' => [OrganizationStatus::Cancelled, false],
]);
