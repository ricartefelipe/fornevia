<?php

declare(strict_types=1);

use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\QuoteTerms;
use Carbon\CarbonImmutable;

/*
| Validade e prazo da reserva em dias comerciais (fuso de São Paulo).
*/

beforeEach(function (): void {
    $this->terms = new QuoteTerms('America/Sao_Paulo', 90, 7);
    // 21/11/2026 22:30 em Brasília (já 22/11 em UTC).
    $this->now = CarbonImmutable::parse('2026-11-22 01:30:00', 'UTC');
});

it('vale até o fim do dia escolhido no fuso comercial', function (): void {
    expect($this->terms->validUntil('2026-11-21', $this->now)->toIso8601String())->toBe('2026-11-22T02:59:59+00:00')
        ->and($this->terms->validUntil('2027-02-19', $this->now)->toDateString())->toBe('2027-02-20')
        ->and($this->terms->defaultValidityDay($this->now, 7))->toBe('2026-11-28');
});

it('recusa validade no passado, além do máximo ou malformada', function (string $day, string $message): void {
    expect(fn () => $this->terms->validUntil($day, $this->now))->toThrow(InvalidQuote::class, $message);
})->with([
    'ontem' => ['2026-11-20', 'antes de hoje'],
    'além de 90 dias' => ['2027-02-20', 'até 90 dias'],
    'data inexistente' => ['2026-02-30', 'Informe a validade'],
    'formato' => ['21/11/2026', 'Informe a validade'],
    'século' => ['1999-01-01', 'Informe a validade'],
]);

it('limita a reserva à validade e ao prazo máximo', function (): void {
    $validUntil = $this->terms->validUntil('2026-12-15', $this->now);

    expect($this->terms->holdUntil('2026-11-28', $validUntil, $this->now)->toIso8601String())->toBe('2026-11-29T02:59:59+00:00')
        ->and(fn () => $this->terms->holdUntil('2026-11-29', $validUntil, $this->now))->toThrow(InvalidQuote::class, 'até 7 dias')
        ->and(fn () => $this->terms->holdUntil('2026-11-20', $validUntil, $this->now))->toThrow(InvalidQuote::class, 'antes de hoje');

    $shortValidity = $this->terms->validUntil('2026-11-23', $this->now);

    expect(fn () => $this->terms->holdUntil('2026-11-24', $shortValidity, $this->now))->toThrow(InvalidQuote::class, 'além da validade')
        ->and($this->terms->holdUntil('2026-11-23', $shortValidity, $this->now)->equalTo($shortValidity))->toBeTrue();
});
