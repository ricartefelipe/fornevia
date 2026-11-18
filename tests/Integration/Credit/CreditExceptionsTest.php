<?php

declare(strict_types=1);

use App\Actions\Credit\GrantCreditException;
use App\Actions\Credit\RevokeCreditException;
use App\Domain\Credit\Exceptions\CreditExceptionNotActive;
use App\Domain\Credit\Exceptions\InvalidCreditValue;
use App\Domain\Shared\Money;
use App\Enums\Identity\SystemRole;
use App\Models\Credit\CreditException;
use Carbon\CarbonImmutable;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Exceções temporárias de limite: somam ao disponível somente durante a
| vigência (dias comerciais no fuso de São Paulo) e enquanto não revogadas.
*/

beforeEach(function (): void {
    $this->clock = Fx::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->finance = $this->tenant->addStaff(SystemRole::Finance);
    $this->company = Fx::company($this->tenant, 'Atacadão Bom Preço');
    $this->account = Fx::open($this->tenant, $this->company, '10.000,00');

    $this->grant = fn (string $amount, string $firstDay, string $lastDay, string $reason = 'Pedido extraordinário de fim de ano'): CreditException => $this->tenant->actingAs(
        $this->finance,
        fn (): CreditException => app(GrantCreditException::class)
            ->handle($this->company, Money::parse($amount), CarbonImmutable::parse($firstDay), CarbonImmutable::parse($lastDay), $reason),
    );
    $this->revoke = fn (CreditException $exception, string $reason = 'Pedido extraordinário cancelado'): CreditException => $this->tenant->actingAs(
        $this->finance,
        fn (): CreditException => app(RevokeCreditException::class)->handle($exception, $reason),
    );
    $this->exceptionsCents = fn (): int => Fx::position($this->tenant, $this->company)->activeExceptions->cents;
});

it('concede exceção vigente do primeiro ao último dia, no fuso do negócio', function (): void {
    $exception = ($this->grant)('5.000,00', '2026-11-18', '2026-11-30');

    expect($exception->valid_from->toIso8601String())->toBe('2026-11-18T03:00:00+00:00')
        ->and($exception->valid_until->toIso8601String())->toBe('2026-12-01T03:00:00+00:00')
        ->and($exception->granted_by_user_id)->toBe($this->finance->user_id)
        ->and($exception->reason)->toBe('Pedido extraordinário de fim de ano');

    $position = Fx::position($this->tenant, $this->company);

    expect($position->activeExceptions->cents)->toBe(500_000)
        ->and($position->available()->cents)->toBe(1_500_000);

    $audit = Fx::audits($this->tenant, 'credit_exception.granted')->sole();

    expect($audit->subject_id)->toBe($exception->id)
        ->and($audit->reason)->toBe('Pedido extraordinário de fim de ano')
        ->and($audit->data)->toEqual([
            'credit_account_id' => $this->account->id,
            'amount_cents' => 500_000,
            'first_day' => '2026-11-18',
            'last_day' => '2026-11-30',
        ]);
});

it('não conta exceção vencida: deixa de valer à meia-noite após o último dia', function (): void {
    ($this->grant)('2.000,00', '2026-11-18', '2026-11-20');

    $this->clock->advance('PT2H');
    expect(($this->exceptionsCents)())->toBe(200_000);

    // 2026-11-21 02:59:59 UTC = 20/11, 23:59:59 em São Paulo.
    $this->clock = Fx::freezeClock('2026-11-21T02:59:59Z');
    expect(($this->exceptionsCents)())->toBe(200_000);

    $this->clock = Fx::freezeClock('2026-11-21T03:00:00Z');
    expect(($this->exceptionsCents)())->toBe(0)
        ->and(Fx::position($this->tenant, $this->company)->available()->cents)->toBe(1_000_000);
});

it('só conta exceção agendada a partir do primeiro dia', function (): void {
    ($this->grant)('3.000,00', '2026-11-25', '2026-12-05');

    expect(($this->exceptionsCents)())->toBe(0);

    Fx::freezeClock('2026-11-25T03:00:00Z');
    expect(($this->exceptionsCents)())->toBe(300_000);
});

it('soma exceções vigentes e desconta a revogada imediatamente', function (): void {
    $first = ($this->grant)('1.000,00', '2026-11-18', '2026-11-30');
    ($this->grant)('500,00', '2026-11-18', '2026-12-10');

    expect(($this->exceptionsCents)())->toBe(150_000);

    $revoked = ($this->revoke)($first);

    expect($revoked->revoked_at?->toIso8601String())->toBe('2026-11-18T15:00:00+00:00')
        ->and($revoked->getAttribute('revoked_by_user_id'))->toBe($this->finance->user_id)
        ->and(($this->exceptionsCents)())->toBe(50_000)
        ->and(Fx::audits($this->tenant, 'credit_exception.revoked')->sole()->reason)->toBe('Pedido extraordinário cancelado');
});

it('permite revogar exceção agendada e recusa revogar de novo ou após o fim da vigência', function (): void {
    $scheduled = ($this->grant)('800,00', '2026-12-01', '2026-12-15');
    ($this->revoke)($scheduled);

    expect(fn () => ($this->revoke)($scheduled))->toThrow(CreditExceptionNotActive::class, 'Esta exceção já foi revogada.');

    $expired = ($this->grant)('800,00', '2026-11-18', '2026-11-19');
    Fx::freezeClock('2026-11-20T03:00:00Z');

    expect(fn () => ($this->revoke)($expired))
        ->toThrow(CreditExceptionNotActive::class, 'A vigência desta exceção já terminou; não há o que revogar.');
});

it('valida valor e vigência da exceção', function (string $amount, string $firstDay, string $lastDay, string $message): void {
    expect(fn () => ($this->grant)($amount, $firstDay, $lastDay))->toThrow(InvalidCreditValue::class, $message);

    expect($this->tenant->system(fn (): int => CreditException::query()->count()))->toBe(0);
})->with([
    'valor zero' => ['0,00', '2026-11-18', '2026-11-30', 'O valor da exceção deve ser maior que zero.'],
    'valor negativo' => ['-10,00', '2026-11-18', '2026-11-30', 'O valor da exceção deve ser maior que zero.'],
    'início no passado' => ['10,00', '2026-11-17', '2026-11-30', 'A vigência da exceção não pode começar antes de hoje.'],
    'fim antes do início' => ['10,00', '2026-11-20', '2026-11-19', 'O último dia da vigência não pode ser anterior ao primeiro.'],
    'mais de 180 dias' => ['10,00', '2026-11-18', '2027-05-17', 'Exceções temporárias valem por no máximo 180 dias; para prazos maiores, altere o limite.'],
]);

it('aceita vigência de exatamente 180 dias', function (): void {
    $exception = ($this->grant)('10,00', '2026-11-18', '2027-05-16');

    expect($exception->valid_until->toIso8601String())->toBe('2027-05-17T03:00:00+00:00');
});
