<?php

declare(strict_types=1);

use App\Domain\Finance\FinancialProgress;
use App\Domain\Finance\Indicators;
use App\Domain\Finance\InstallmentSchedule;
use App\Domain\Finance\RefundLimit;
use App\Domain\Shared\Money;
use App\Enums\Ordering\FinancialStatus;
use Carbon\CarbonImmutable;

/*
| Regras puras de contas a receber (RF-100, RN-101) e indicadores (RF-102).
*/

it('divide a fatura nas parcelas da condição com a diferença na última', function (): void {
    $issue = CarbonImmutable::parse('2026-11-23 15:00:00');
    $parts = InstallmentSchedule::split(Money::ofCents(10_000), [30, 60, 90], $issue);

    expect(array_map(static fn (array $p): array => [$p['sequence'], $p['due_date']->toDateString(), $p['amount']->cents], $parts))->toBe([
        [1, '2026-12-23', 3_333],
        [2, '2027-01-22', 3_333],
        [3, '2027-02-21', 3_334],
    ]);
});

it('vence à vista no dia da emissão e não cria parcela sem valor', function (): void {
    $issue = CarbonImmutable::parse('2026-11-23');

    expect(InstallmentSchedule::split(Money::ofCents(500), [], $issue)[0]['due_date']->toDateString())->toBe('2026-11-23')
        ->and(InstallmentSchedule::split(Money::ofCents(2), [10, 20, 30], $issue))->toHaveCount(1)
        ->and(InstallmentSchedule::split(Money::ofCents(2), [10, 20, 30], $issue)[0]['due_date']->toDateString())->toBe('2026-12-23')
        ->and(fn () => InstallmentSchedule::split(Money::zero(), [0], $issue))->toThrow(InvalidArgumentException::class);
});

it('calcula a situação financeira pelo faturado e pelo quitado', function (int $due, int $invoiced, int $settled, FinancialStatus $expected): void {
    expect(FinancialProgress::of(Money::ofCents($due), Money::ofCents($invoiced), Money::ofCents($settled)))->toBe($expected);
})->with([
    'sem fatura' => [1_000, 0, 0, FinancialStatus::NotInvoiced],
    'parte faturada' => [1_000, 400, 0, FinancialStatus::PartiallyInvoiced],
    'tudo faturado' => [1_000, 1_000, 0, FinancialStatus::Invoiced],
    'parte paga' => [1_000, 400, 100, FinancialStatus::PartiallyPaid],
    'faturado e parte paga' => [1_000, 1_000, 999, FinancialStatus::PartiallyPaid],
    'pago' => [1_000, 1_000, 1_000, FinancialStatus::Paid],
    'faturado parcial quitado' => [1_000, 400, 400, FinancialStatus::PartiallyPaid],
]);

it('limita o reembolso ao pago menos o já reembolsado e o pendente', function (): void {
    expect(RefundLimit::available(Money::ofCents(1_000), Money::ofCents(300), Money::ofCents(200))->cents)->toBe(500)
        ->and(RefundLimit::available(Money::ofCents(1_000), Money::ofCents(800), Money::ofCents(300))->cents)->toBe(0);
});

it('calcula os indicadores e devolve indefinido sem base', function (): void {
    expect(Indicators::averageTicket(Money::ofCents(100_000), 3)?->cents)->toBe(33_333)
        ->and(Indicators::averageTicket(Money::ofCents(100), 0))->toBeNull()
        ->and(Indicators::ratio(1, 3)?->basisPoints)->toBe(3_333)
        ->and(Indicators::ratio(5, 3)?->basisPoints)->toBe(10_000)
        ->and(Indicators::ratio(1, 0))->toBeNull()
        ->and(Indicators::margin(Money::ofCents(1_000), Money::ofCents(700))?->basisPoints)->toBe(3_000)
        ->and(Indicators::margin(Money::ofCents(1_000), Money::ofCents(1_200))?->basisPoints)->toBe(-2_000)
        ->and(Indicators::margin(Money::zero(), Money::zero()))->toBeNull();
});
