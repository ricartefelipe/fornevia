<?php

declare(strict_types=1);

use App\Actions\Credit\BlockCreditAccount;
use App\Actions\Credit\ChangeCreditLimit;
use App\Actions\Credit\ChangeOverdueTolerance;
use App\Actions\Credit\GrantCreditException;
use App\Actions\Credit\OpenCreditAccount;
use App\Actions\Credit\RevokeCreditException;
use App\Actions\Credit\UnblockCreditAccount;
use App\Domain\Buyers\Exceptions\JustificationRequired;
use App\Domain\Credit\CreditDecision;
use App\Domain\Credit\Exceptions\CreditAccountAlreadyOpen;
use App\Domain\Credit\Exceptions\CreditAccountNotOpen;
use App\Domain\Credit\Exceptions\CreditAccountUnchanged;
use App\Domain\Credit\Exceptions\InvalidCreditValue;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Enums\Credit\CreditAccountStatus;
use App\Enums\Credit\CreditMovementType;
use App\Enums\Identity\SystemRole;
use Carbon\CarbonImmutable;
use Tests\Integration\Credit\CreditFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Gestão da conta de crédito pela equipe: abertura, limite, bloqueio,
| tolerância de vencidos, histórico de limite e auditoria com justificativa.
*/

beforeEach(function (): void {
    Fx::freezeClock();
    $this->tenant = TenantScenario::create();
    $this->finance = $this->tenant->addStaff(SystemRole::Finance);
    $this->company = Fx::company($this->tenant, 'Construtora Serra Azul');

    $this->as = fn (Closure $callback): mixed => $this->tenant->actingAs($this->finance, $callback);
});

it('abre a conta com limite e tolerância, saldos zerados, histórico e auditoria', function (): void {
    $account = ($this->as)(fn () => app(OpenCreditAccount::class)
        ->handle($this->company, Money::parse('50.000,00'), 10, '  Análise de crédito aprovada em comitê  '));

    $stored = Fx::account($this->tenant, $this->company);

    expect($account->id)->toBe($stored?->id)
        ->and($stored->limit_cents->cents)->toBe(5_000_000)
        ->and($stored->committed_cents->cents)->toBe(0)
        ->and($stored->receivable_cents->cents)->toBe(0)
        ->and($stored->status)->toBe(CreditAccountStatus::Active)
        ->and($stored->blocked_reason)->toBeNull()
        ->and($stored->overdue_tolerance_days)->toBe(10)
        ->and(Fx::limitChanges($this->tenant))->toBe([
            [0, 5_000_000, 'active', 'active', 'Análise de crédito aprovada em comitê', $this->finance->user_id],
        ]);

    $audit = Fx::audits($this->tenant, 'credit_account.opened')->sole();

    expect($audit->subject_id)->toBe($account->id)
        ->and($audit->reason)->toBe('Análise de crédito aprovada em comitê')
        ->and($audit->data)->toEqual(['limit_cents' => 5_000_000, 'overdue_tolerance_days' => 10])
        ->and($audit->actor_user_id)->toBe($this->finance->user_id);
});

it('recusa abrir uma segunda conta para a mesma empresa', function (): void {
    Fx::open($this->tenant, $this->company, '1.000,00');

    expect(fn () => ($this->as)(fn () => app(OpenCreditAccount::class)
        ->handle($this->company, Money::parse('2.000,00'), 0, 'Nova tentativa de abertura')))
        ->toThrow(CreditAccountAlreadyOpen::class, 'A empresa Construtora Serra Azul já tem conta de crédito comercial.');

    expect(Fx::limitChanges($this->tenant))->toHaveCount(1);
});

it('recusa limite negativo e tolerância fora do intervalo', function (): void {
    expect(fn () => ($this->as)(fn () => app(OpenCreditAccount::class)
        ->handle($this->company, Money::parse('-1,00'), 0, 'Limite de teste negativo')))
        ->toThrow(InvalidCreditValue::class, 'O limite de crédito não pode ser negativo.');

    expect(fn () => ($this->as)(fn () => app(OpenCreditAccount::class)
        ->handle($this->company, Money::parse('1,00'), 366, 'Tolerância longa demais')))
        ->toThrow(InvalidCreditValue::class, 'A tolerância de vencidos deve ficar entre 0 e 365 dias.');

    expect(Fx::account($this->tenant, $this->company))->toBeNull();
});

it('altera o limite com histórico do valor anterior e auditoria', function (): void {
    Fx::open($this->tenant, $this->company, '50.000,00');

    $account = ($this->as)(fn () => app(ChangeCreditLimit::class)
        ->handle($this->company, Money::parse('80.000,00'), 'Aumento após revisão do faturamento anual'));

    expect($account->limit_cents->cents)->toBe(8_000_000)
        ->and(Fx::account($this->tenant, $this->company)?->limit_cents->cents)->toBe(8_000_000)
        ->and(Fx::limitChanges($this->tenant)[1])->toBe(
            [5_000_000, 8_000_000, 'active', 'active', 'Aumento após revisão do faturamento anual', $this->finance->user_id],
        );

    $audit = Fx::audits($this->tenant, 'credit_account.limit_changed')->sole();

    expect($audit->data)->toEqual(['from_cents' => 5_000_000, 'to_cents' => 8_000_000])
        ->and($audit->reason)->toBe('Aumento após revisão do faturamento anual');
});

it('recusa limite igual ao atual e conta inexistente', function (): void {
    expect(fn () => ($this->as)(fn () => app(ChangeCreditLimit::class)
        ->handle($this->company, Money::parse('1.000,00'), 'Empresa ainda sem conta')))
        ->toThrow(CreditAccountNotOpen::class, 'A empresa Construtora Serra Azul não tem conta de crédito comercial aberta.');

    Fx::open($this->tenant, $this->company, '1.000,00');

    expect(fn () => ($this->as)(fn () => app(ChangeCreditLimit::class)
        ->handle($this->company, Money::parse('1000'), 'Mesmo valor do limite atual')))
        ->toThrow(CreditAccountUnchanged::class, 'O limite já é R$ 1.000,00.');

    expect(Fx::limitChanges($this->tenant))->toHaveCount(1)
        ->and(Fx::audits($this->tenant, 'credit_account.limit_changed'))->toBeEmpty();
});

it('permite reduzir o limite abaixo da exposição: o disponível fica negativo e o checkout recusa', function (): void {
    $account = Fx::open($this->tenant, $this->company, '50.000,00');
    Fx::movement($this->tenant, $account, CreditMovementType::OrderCommitment, 3_000_000, 0);

    ($this->as)(fn () => app(ChangeCreditLimit::class)
        ->handle($this->company, Money::parse('20.000,00'), 'Redução por atraso recorrente nos pagamentos'));

    $position = Fx::position($this->tenant, $this->company);

    expect($position->exposure()->cents)->toBe(3_000_000)
        ->and($position->available()->cents)->toBe(-1_000_000)
        ->and($position->decide(Money::ofCents(1)))->toBe(CreditDecision::Insufficient)
        ->and(Fx::account($this->tenant, $this->company)?->committed_cents->cents)->toBe(3_000_000);
});

it('bloqueia e desbloqueia com motivo, histórico de situação e auditoria', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00');

    ($this->as)(fn () => app(BlockCreditAccount::class)->handle($this->company, 'Títulos protestados em cartório'));

    $blocked = Fx::account($this->tenant, $this->company);

    expect($blocked?->status)->toBe(CreditAccountStatus::Blocked)
        ->and($blocked?->blocked_reason)->toBe('Títulos protestados em cartório')
        ->and(Fx::position($this->tenant, $this->company)->decide(Money::ofCents(1)))->toBe(CreditDecision::Blocked);

    expect(fn () => ($this->as)(fn () => app(BlockCreditAccount::class)->handle($this->company, 'Bloqueio repetido')))
        ->toThrow(CreditAccountUnchanged::class, 'O crédito desta empresa já está bloqueado.');

    ($this->as)(fn () => app(UnblockCreditAccount::class)->handle($this->company, 'Protestos quitados e baixados'));

    $active = Fx::account($this->tenant, $this->company);

    expect($active?->status)->toBe(CreditAccountStatus::Active)
        ->and($active?->blocked_reason)->toBeNull()
        ->and(Fx::position($this->tenant, $this->company)->decide(Money::ofCents(1)))->toBe(CreditDecision::Approved)
        ->and(array_slice(Fx::limitChanges($this->tenant), 1))->toBe([
            [1_000_000, 1_000_000, 'active', 'blocked', 'Títulos protestados em cartório', $this->finance->user_id],
            [1_000_000, 1_000_000, 'blocked', 'active', 'Protestos quitados e baixados', $this->finance->user_id],
        ])
        ->and(Fx::audits($this->tenant, 'credit_account.unblocked')->sole()->data)->toEqual([
            'from' => 'blocked',
            'to' => 'active',
            'blocked_reason' => 'Títulos protestados em cartório',
        ]);

    expect(fn () => ($this->as)(fn () => app(UnblockCreditAccount::class)->handle($this->company, 'Desbloqueio repetido')))
        ->toThrow(CreditAccountUnchanged::class, 'O crédito desta empresa não está bloqueado.');
});

it('altera a tolerância de vencidos com auditoria', function (): void {
    Fx::open($this->tenant, $this->company, '10.000,00', toleranceDays: 5);

    ($this->as)(fn () => app(ChangeOverdueTolerance::class)->handle($this->company, 15, 'Prazo de compensação bancária do cliente'));

    expect(Fx::account($this->tenant, $this->company)?->overdue_tolerance_days)->toBe(15)
        ->and(Fx::audits($this->tenant, 'credit_account.overdue_tolerance_changed')->sole()->data)->toEqual(['from' => 5, 'to' => 15]);

    expect(fn () => ($this->as)(fn () => app(ChangeOverdueTolerance::class)->handle($this->company, 15, 'Mesma tolerância')))
        ->toThrow(CreditAccountUnchanged::class, 'A tolerância já é de 15 dia(s).');

    expect(fn () => ($this->as)(fn () => app(ChangeOverdueTolerance::class)->handle($this->company, -1, 'Tolerância negativa')))
        ->toThrow(InvalidCreditValue::class);
});

it('exige justificativa em toda alteração de crédito', function (Closure $operation): void {
    Fx::open($this->tenant, $this->company, '10.000,00');
    $exception = Fx::grant($this->tenant, $this->company, '1.000,00', '2026-11-18', '2026-11-30');
    $before = Fx::creditAuditCount($this->tenant);

    expect(fn () => ($this->as)(fn () => $operation($this->company, $exception)))
        ->toThrow(JustificationRequired::class, 'Informe a justificativa (mínimo de 5 caracteres).');

    expect(Fx::creditAuditCount($this->tenant))->toBe($before)
        ->and(Fx::limitChanges($this->tenant))->toHaveCount(1);
})->with([
    'alteração de limite' => [static fn ($company) => app(ChangeCreditLimit::class)->handle($company, Money::parse('5.000,00'), '   ')],
    'bloqueio' => [static fn ($company) => app(BlockCreditAccount::class)->handle($company, 'abc')],
    'tolerância' => [static fn ($company) => app(ChangeOverdueTolerance::class)->handle($company, 3, '')],
    'exceção' => [static fn ($company) => app(GrantCreditException::class)
        ->handle($company, Money::parse('500,00'), CarbonImmutable::parse('2026-11-18'), CarbonImmutable::parse('2026-11-20'), ' ok ')],
    'revogação de exceção' => [static fn ($company, $exception) => app(RevokeCreditException::class)->handle($exception, '')],
]);

it('exige justificativa na abertura e no desbloqueio', function (): void {
    expect(fn () => ($this->as)(fn () => app(OpenCreditAccount::class)->handle($this->company, Money::parse('1,00'), 0, 'curt')))
        ->toThrow(JustificationRequired::class);

    Fx::open($this->tenant, $this->company, '1,00');
    ($this->as)(fn () => app(BlockCreditAccount::class)->handle($this->company, 'Bloqueio preventivo'));

    expect(fn () => ($this->as)(fn () => app(UnblockCreditAccount::class)->handle($this->company, str_repeat('a', 501))))
        ->toThrow(JustificationRequired::class, 'A justificativa deve ter no máximo 500 caracteres.');

    expect(Fx::account($this->tenant, $this->company)?->status)->toBe(CreditAccountStatus::Blocked);
});

it('recusa limite que, somado às exceções em aberto, sairia do intervalo suportado', function (): void {
    Fx::open($this->tenant, $this->company, '1.000,00');
    Fx::grant($this->tenant, $this->company, '1.000,00', '2026-11-18', '2026-11-30');

    expect(fn () => ($this->as)(fn () => app(ChangeCreditLimit::class)
        ->handle($this->company, Money::ofCents(Money::MAX_CENTS), 'Limite máximo do sistema')))
        ->toThrow(AmountOutOfRange::class, 'O limite somado às exceções em aberto excede o valor máximo suportado.');

    ($this->as)(fn () => app(ChangeCreditLimit::class)
        ->handle($this->company, Money::ofCents(Money::MAX_CENTS - 100_000), 'Limite máximo menos as exceções'));

    expect(fn () => Fx::grant($this->tenant, $this->company, '0,01', '2026-11-18', '2026-11-19'))
        ->toThrow(AmountOutOfRange::class);

    expect(Fx::position($this->tenant, $this->company)->available()->cents)->toBe(Money::MAX_CENTS);
});
