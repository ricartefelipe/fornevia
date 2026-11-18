<?php

declare(strict_types=1);

use App\Actions\Pricing\SavePaymentTerm;
use App\Actions\Pricing\UpdatePricingSettings;
use App\Data\Pricing\PaymentTermData;
use App\Domain\Pricing\Exceptions\InvalidMinimumMargin;
use App\Domain\Pricing\Exceptions\InvalidPaymentTerm;
use App\Domain\Pricing\Exceptions\PaymentTermCodeTaken;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Membership;
use App\Models\Pricing\PaymentTerm;
use App\Models\Pricing\PricingPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| O onboarding cria condições padrão (AVISTA, 28D, 30-60, 30-60-90) e a
| configuração de preços com margem mínima de 10%.
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->save = fn (Membership $actor, ?PaymentTerm $term, PaymentTermData $data): PaymentTerm => $this->tenant->actingAs(
        $actor,
        static fn (): PaymentTerm => app(SavePaymentTerm::class)->handle($term, $data),
    );

    $this->term = fn (string $code, ?TenantScenario $tenant = null): ?array => ($tenant ?? $this->tenant)->system(static function () use ($code): ?array {
        $term = PaymentTerm::query()->where('code', $code)->first();

        return $term !== null ? [$term->name, $term->installment_days, $term->requires_credit, $term->is_active] : null;
    });

    $this->count = fn (): int => $this->tenant->system(static fn (): int => PaymentTerm::query()->count());

    $this->margin = fn (Membership $actor, int $bps): PricingPolicy => $this->tenant->actingAs(
        $actor,
        static fn (): PricingPolicy => app(UpdatePricingSettings::class)->handle($bps),
    );

    $this->storedMargin = fn (?TenantScenario $tenant = null): int => ($tenant ?? $this->tenant)->system(
        static fn (): int => (int) PricingPolicy::query()->value('min_margin_bps'),
    );
});

it('cadastra condições à vista e a prazo com código normalizado e auditoria', function (): void {
    $before = ($this->count)();

    ($this->save)($this->manager, null, new PaymentTermData(' pix ', ' PIX à vista ', [0], false));
    ($this->save)($this->manager, null, new PaymentTermData('45-75', 'Boleto 45/75', [45, 75], true));

    expect(($this->term)('PIX'))->toBe(['PIX à vista', [0], false, true])
        ->and(($this->term)('45-75'))->toBe(['Boleto 45/75', [45, 75], true, true])
        ->and(($this->count)())->toBe($before + 2)
        ->and(Fx::auditActions($this->tenant, 'payment_term.'))->toBe(['payment_term.created', 'payment_term.created']);
});

it('altera condição existente registrando antes e depois, sem mudar a versão de preços', function (): void {
    $term = ($this->save)($this->manager, null, new PaymentTermData('21DD', 'Boleto 21 dias', [21], true));
    $version = Fx::version($this->tenant);

    ($this->save)($this->manager, $term, new PaymentTermData('21DD', 'Boleto 21 dias (inativa)', [21], true, false));

    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'payment_term.updated')->sole());

    expect(($this->term)('21DD'))->toBe(['Boleto 21 dias (inativa)', [21], true, false])
        ->and($log->data['before']['is_active'])->toBeTrue()
        ->and($log->data['after']['is_active'])->toBeFalse()
        ->and(Fx::version($this->tenant))->toBe($version);
});

it('aceita parcelas com vencimentos iguais em sequência não decrescente', function (): void {
    ($this->save)($this->manager, null, new PaymentTermData('ENTRADA-30', 'Entrada e 30 dias', [0, 0, 30], true));

    expect(($this->term)('ENTRADA-30')[1])->toBe([0, 0, 30]);
});

it('valida parcelas, dias, modalidade, código e nome', function (PaymentTermData $data, string $field, string $message): void {
    $before = ($this->count)();

    try {
        ($this->save)($this->manager, null, $data);
        $this->fail('A condição não deveria ter sido aceita.');
    } catch (InvalidPaymentTerm $e) {
        expect($e->errorCode())->toBe('invalid_payment_term')
            ->and($e->details()['field'])->toBe($field)
            ->and($e->getMessage())->toContain($message);
    }

    expect(($this->count)())->toBe($before);
})->with([
    'sem parcelas' => [new PaymentTermData('X', 'Sem parcelas', [], true), 'installment_days', 'Informe de 1 a 12 parcelas.'],
    'treze parcelas' => [new PaymentTermData('X', 'Treze parcelas', range(10, 130, 10), true), 'installment_days', 'Informe de 1 a 12 parcelas.'],
    'dia negativo' => [new PaymentTermData('X', 'Dia negativo', [-1], true), 'installment_days', 'entre 0 e 365'],
    'dia acima de 365' => [new PaymentTermData('X', 'Dia acima', [30, 366], true), 'installment_days', 'entre 0 e 365'],
    'ordem decrescente' => [new PaymentTermData('X', 'Decrescente', [60, 30], true), 'installment_days', 'ordem crescente'],
    'à vista com prazo' => [new PaymentTermData('X', 'À vista inválida', [30], false), 'installment_days', 'Condição à vista tem uma única parcela no dia 0.'],
    'à vista parcelada' => [new PaymentTermData('X', 'À vista parcelada', [0, 0], false), 'installment_days', 'Condição à vista'],
    'a prazo sem prazo' => [new PaymentTermData('X', 'A prazo inválida', [0], true), 'installment_days', 'ao menos um vencimento após o faturamento'],
    'código inválido' => [new PaymentTermData('30 DIAS', 'Código com espaço', [30], true), 'code', 'Informe um código'],
    'código longo' => [new PaymentTermData(str_repeat('A', 21), 'Código longo', [30], true), 'code', 'Informe um código'],
    'nome vazio' => [new PaymentTermData('X', '  ', [30], true), 'name', 'Informe um nome'],
]);

it('recusa código duplicado no tenant e aceita o mesmo código em outro tenant', function (): void {
    $other = TenantScenario::create();
    ($this->save)($this->manager, null, new PaymentTermData('BOLETO-15', 'Boleto 15 dias', [15], true));

    expect(fn () => ($this->save)($this->manager, null, new PaymentTermData('boleto-15', 'Boleto quinzenal', [15], true)))
        ->toThrow(PaymentTermCodeTaken::class, 'Já existe uma condição de pagamento com o código BOLETO-15.')
        ->and(fn () => ($this->save)($this->manager, null, new PaymentTermData('avista', 'À vista PIX', [0], false)))
        ->toThrow(PaymentTermCodeTaken::class, 'AVISTA');

    $other->actingAs($other->ownerMembership, static fn () => app(SavePaymentTerm::class)->handle(null, new PaymentTermData('BOLETO-15', 'Boleto 15 dias', [15], true)));

    expect(($this->term)('BOLETO-15', $other))->toBe(['Boleto 15 dias', [15], true, true])
        ->and(($this->term)('AVISTA'))->toBe(['À vista', [0], false, true]);
});

it('altera o código de uma condição para outro ainda livre', function (): void {
    $term = ($this->save)($this->manager, null, new PaymentTermData('BOLETO-15', 'Boleto 15 dias', [15], true));

    ($this->save)($this->manager, $term, new PaymentTermData('BOLETO-14', 'Boleto 14 dias', [14], true));

    expect(($this->term)('BOLETO-15'))->toBeNull()
        ->and(($this->term)('BOLETO-14'))->toBe(['Boleto 14 dias', [14], true, true])
        ->and(fn () => ($this->save)($this->manager, $term, new PaymentTermData('AVISTA', 'À vista', [0], false)))
        ->toThrow(PaymentTermCodeTaken::class);
});

it('exige pricing.manage e trata condição de outro tenant como inexistente', function (): void {
    $other = TenantScenario::create();
    $foreign = $other->actingAs($other->ownerMembership, static fn () => app(SavePaymentTerm::class)->handle(null, new PaymentTermData('35DD', 'Boleto 35 dias', [35], true)));
    $viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $finance = $this->tenant->addStaff(SystemRole::Finance);
    $before = ($this->count)();

    expect(fn () => ($this->save)($viewer, null, new PaymentTermData('PIX', 'PIX', [0], false)))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($finance, null, new PaymentTermData('PIX', 'PIX', [0], false)))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($this->manager, $foreign, new PaymentTermData('35DD', 'Alterada', [45], true)))->toThrow(ModelNotFoundException::class);

    expect(($this->count)())->toBe($before)
        ->and(($this->term)('35DD', $other))->toBe(['Boleto 35 dias', [35], true, true]);
});

it('define a margem mínima padrão com auditoria, sem alterar a versão de preços', function (): void {
    $version = Fx::version($this->tenant);

    expect(($this->storedMargin)())->toBe(1_000);

    $settings = ($this->margin)($this->manager, 1_250);
    ($this->margin)($this->manager, 1_250);
    ($this->margin)($this->manager, 1_800);

    $logs = $this->tenant->system(static fn () => AuditLog::query()->where('action', 'pricing_settings.updated')->orderBy('created_at')->orderBy('id')->get());

    expect($settings->min_margin_bps)->toBe(1_250)
        ->and(($this->storedMargin)())->toBe(1_800)
        ->and($logs)->toHaveCount(2)
        ->and($logs->first()->data)->toEqual(['before' => ['min_margin_bps' => 1_000], 'after' => ['min_margin_bps' => 1_250]])
        ->and($logs->last()->data)->toEqual(['before' => ['min_margin_bps' => 1_250], 'after' => ['min_margin_bps' => 1_800]])
        ->and(Fx::version($this->tenant))->toBe($version);
});

it('valida o intervalo da margem mínima e exige pricing.manage', function (): void {
    $rep = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->margin)($this->manager, 10_001))->toThrow(InvalidMinimumMargin::class)
        ->and(fn () => ($this->margin)($this->manager, -10_001))->toThrow(InvalidMinimumMargin::class)
        ->and(fn () => ($this->margin)($rep, 500))->toThrow(AuthorizationException::class);

    expect(($this->storedMargin)())->toBe(1_000);
});

it('aceita margem mínima negativa dentro do intervalo do banco', function (): void {
    ($this->margin)($this->manager, -500);

    expect(($this->storedMargin)())->toBe(-500);
});

it('mantém a margem mínima de cada tenant isolada', function (): void {
    $other = TenantScenario::create();

    ($this->margin)($this->manager, 1_500);
    $other->actingAs($other->ownerMembership, static fn () => app(UpdatePricingSettings::class)->handle(900));

    expect($this->tenant->system(static fn (): array => PricingPolicy::query()->pluck('min_margin_bps')->all()))->toBe([1_500])
        ->and($other->system(static fn (): array => PricingPolicy::query()->pluck('min_margin_bps')->all()))->toBe([900]);
});
