<?php

declare(strict_types=1);

use App\Domain\Buyers\BrazilianState;
use App\Domain\Buyers\Exceptions\InvalidRecordField;
use App\Domain\Buyers\Justification;
use App\Domain\Buyers\PostalCode;
use App\Domain\Buyers\RecordText;

/*
| Campos de cadastro: texto obrigatório e opcional com limite, código,
| e-mail, CEP, UF e justificativa.
*/

function recordFieldError(Closure $call): array
{
    try {
        $call();
    } catch (App\Domain\Shared\BusinessRuleViolation $e) {
        return [$e->getMessage(), $e->details()];
    }

    throw new RuntimeException('Nenhuma exceção lançada.');
}

it('apara e limita o texto obrigatório e o opcional', function (): void {
    expect(RecordText::required('  Alfa  ', 'name', 'o nome', 4))->toBe('Alfa')
        ->and(RecordText::required('Ábcd', 'name', 'o nome', 4))->toBe('Ábcd')
        ->and(recordFieldError(fn () => RecordText::required('   ', 'name', 'o nome', 10)))->toBe(['Informe o nome.', ['field' => 'name']])
        ->and(recordFieldError(fn () => RecordText::required('Alfas', 'name', 'o nome', 4)))->toBe(['Nome deve ter no máximo 4 caracteres.', ['field' => 'name']])
        ->and(RecordText::optional(null, 'note', 'a nota', 5))->toBeNull()
        ->and(RecordText::optional('  ', 'note', 'a nota', 5))->toBeNull()
        ->and(RecordText::optional(' nota ', 'note', 'a nota', 4))->toBe('nota')
        ->and(recordFieldError(fn () => RecordText::optional('notas', 'note', 'as notas', 4)))->toBe(['Notas deve ter no máximo 4 caracteres.', ['field' => 'note']]);
});

it('normaliza e valida código e e-mail', function (): void {
    expect(RecordText::code(' cd-1.a_b ', 'code', 'o código', 20))->toBe('CD-1.A_B')
        ->and(recordFieldError(fn () => RecordText::code('-cd', 'code', 'o código', 20)))->toBe(['Código deve conter apenas letras, números, ponto, hífen ou sublinhado.', ['field' => 'code']])
        ->and(recordFieldError(fn () => RecordText::code('c d', 'code', 'a sigla', 20))[0])->toBe('Sigla deve conter apenas letras, números, ponto, hífen ou sublinhado.')
        ->and(RecordText::email(' Compras@Alfa.COM.br ', 'email'))->toBe('compras@alfa.com.br')
        ->and(RecordText::email(null, 'email'))->toBeNull()
        ->and(RecordText::email(' ', 'email'))->toBeNull()
        ->and(recordFieldError(fn () => RecordText::email('sem-arroba', 'contact_email')))->toBe(['Informe um e-mail válido.', ['field' => 'contact_email']])
        ->and(recordFieldError(fn () => RecordText::email('a@b.co', 'email', 5))[0])->toBe('E-mail deve ter no máximo 5 caracteres.');
});

it('aceita CEP com ou sem hífen e formata', function (): void {
    expect(PostalCode::fromString(' 13050-000 ')->digits)->toBe('13050000')
        ->and(PostalCode::fromString('13050000')->formatted())->toBe('13050-000')
        ->and(PostalCode::format('1305000'))->toBe('1305000')
        ->and(PostalCode::format('1305000a'))->toBe('1305000a');

    foreach (['1305-000', '130500000', '13050--000', '00000-000', '', 'abcde-fgh'] as $invalid) {
        expect(recordFieldError(fn () => PostalCode::fromString($invalid)))->toBe(['Informe um CEP válido com 8 dígitos.', ['field' => 'postal_code']]);
    }
});

it('reconhece a UF e lista as opções em ordem', function (): void {
    expect(BrazilianState::fromInput(' sp '))->toBe(BrazilianState::SP)
        ->and(BrazilianState::SP->label())->toBe('São Paulo')
        ->and(BrazilianState::DF->label())->toBe('Distrito Federal')
        ->and(recordFieldError(fn () => BrazilianState::fromInput('XX')))->toBe(['Informe uma UF válida.', ['field' => 'state']]);

    $options = BrazilianState::options();
    expect($options)->toHaveCount(27)
        ->and(array_key_first($options))->toBe('AC')
        ->and(array_key_last($options))->toBe('TO')
        ->and($options['MG'])->toBe('MG · Minas Gerais');

    foreach (BrazilianState::cases() as $state) {
        expect($state->label())->not->toBe('')->not->toBe($state->value);
    }
});

it('exige justificativa entre 5 e 500 caracteres', function (): void {
    expect(Justification::fromString('  Motivo  ')->text)->toBe('Motivo')
        ->and(Justification::fromString(str_repeat('a', 5))->text)->toHaveLength(5)
        ->and(Justification::fromString(str_repeat('á', 500))->text)->toHaveLength(500)
        ->and(recordFieldError(fn () => Justification::fromString(' abcd ')))->toBe(['Informe a justificativa (mínimo de 5 caracteres).', ['field' => 'reason']])
        ->and(recordFieldError(fn () => Justification::fromString(str_repeat('a', 501))))->toBe(['A justificativa deve ter no máximo 500 caracteres.', ['field' => 'reason']]);
});

it('usa a exceção de campo de cadastro', function (): void {
    expect(fn () => RecordText::required('', 'name', 'o nome', 1))->toThrow(InvalidRecordField::class);
});
