<?php

declare(strict_types=1);

use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\InvalidDocument;

/*
| CNPJs fictícios, gerados apenas para teste (dígitos verificadores
| calculados a partir de raízes arbitrárias).
*/

it('aceita CNPJ válido com ou sem máscara e armazena somente os dígitos', function (string $input, string $digits): void {
    $cnpj = Cnpj::fromString($input);

    expect($cnpj->digits)->toBe($digits)
        ->and(Cnpj::isValid($digits))->toBeTrue();
})->with([
    'somente dígitos' => ['73019284000131', '73019284000131'],
    'com máscara' => ['73.019.284/0001-31', '73019284000131'],
    'com espaços nas bordas' => ['  50.481.736/0001-05 ', '50481736000105'],
    'filial (ordem 0002)' => ['28.635.109/0002-03', '28635109000203'],
    'filial (ordem 0003)' => ['61347058000362', '61347058000362'],
    'dígitos verificadores iguais a zero (resto menor que 2)' => ['40.000.089/0001-00', '40000089000100'],
]);

it('recusa CNPJ com dígito verificador incorreto', function (string $input): void {
    expect(Cnpj::isValid(preg_replace('/\D/', '', $input) ?? ''))->toBeFalse();

    Cnpj::fromString($input);
})->with([
    'primeiro dígito incorreto' => ['73.019.284/0001-21'],
    'segundo dígito incorreto' => ['73.019.284/0001-32'],
    'dígitos verificadores trocados' => ['73019284000113'],
    'raiz alterada mantendo os dígitos' => ['73019285000131'],
    'ordem da filial alterada mantendo os dígitos' => ['28635109000103'],
])->throws(InvalidDocument::class, 'CNPJ inválido.');

it('recusa sequências de um único dígito repetido, inclusive as que passariam no cálculo', function (string $digits): void {
    expect(Cnpj::isValid($digits))->toBeFalse()
        ->and(fn () => Cnpj::fromString($digits))->toThrow(InvalidDocument::class);
})->with(array_map(static fn (int $d): string => str_repeat((string) $d, 14), range(0, 9)));

it('recusa entradas com quantidade de dígitos diferente de 14', function (string $input): void {
    Cnpj::fromString($input);
})->with([
    'vazio' => [''],
    'somente pontuação' => ['../-'],
    'treze dígitos' => ['7301928400013'],
    'quinze dígitos' => ['730192840001310'],
    'texto sem dígitos' => ['não informado'],
])->throws(InvalidDocument::class);

it('valida em isValid apenas a forma normalizada com 14 dígitos', function (string $value): void {
    expect(Cnpj::isValid($value))->toBeFalse();
})->with([
    'com máscara' => ['73.019.284/0001-31'],
    'com quebra de linha ao final' => ["73019284000131\n"],
    'com espaço' => [' 73019284000131'],
    'com dígitos não ASCII' => ['７３０１９２８４０００１３１'],
]);

it('expõe código de erro estável e o campo afetado ao recusar o documento', function (): void {
    try {
        Cnpj::fromString('11.111.111/1111-11');
        $this->fail('O CNPJ deveria ter sido recusado.');
    } catch (InvalidDocument $e) {
        expect($e)->toBeInstanceOf(BusinessRuleViolation::class)
            ->and($e->errorCode())->toBe('invalid_document')
            ->and($e->details())->toBe(['field' => 'document'])
            ->and($e->getMessage())->toBe('CNPJ inválido.');
    }
});

it('formata o CNPJ validado com a máscara oficial', function (): void {
    expect(Cnpj::fromString('73019284000131')->formatted())->toBe('73.019.284/0001-31')
        ->and(Cnpj::fromString('28.635.109/0002-03')->formatted())->toBe('28.635.109/0002-03');
});

it('formata 14 dígitos já persistidos sem revalidar os dígitos verificadores', function (): void {
    expect(Cnpj::isValid('12345678901234'))->toBeFalse()
        ->and(Cnpj::format('12345678901234'))->toBe('12.345.678/9012-34')
        ->and(Cnpj::format('00000000000000'))->toBe('00.000.000/0000-00');
});

it('devolve sem alteração valores que não sejam exatamente 14 dígitos', function (string $value): void {
    expect(Cnpj::format($value))->toBe($value);
})->with([
    'vazio' => [''],
    'já formatado' => ['73.019.284/0001-31'],
    'treze dígitos' => ['7301928400013'],
    'quinze dígitos' => ['730192840001310'],
    'com quebra de linha ao final' => ["73019284000131\n"],
    'com letras' => ['7301928400013A'],
]);
