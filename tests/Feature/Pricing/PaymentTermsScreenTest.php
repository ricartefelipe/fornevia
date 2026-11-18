<?php

declare(strict_types=1);

use App\Actions\Pricing\SavePaymentTerm;
use App\Data\Pricing\PaymentTermData;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Pricing\PaymentTermsIndex;
use App\Models\Pricing\PaymentTerm;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);

    $this->term = fn (string $code, ?TenantScenario $tenant = null): ?array => ($tenant ?? $this->tenant)->system(static function () use ($code): ?array {
        $term = PaymentTerm::query()->where('code', $code)->first();

        return $term !== null ? [$term->name, $term->installment_days, $term->requires_credit, $term->is_active] : null;
    });
});

it('lista as condições padrão do onboarding', function (): void {
    Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(PaymentTermsIndex::class)
        ->assertSee(['AVISTA', '28D', '30-60', '30-60-90'])
        ->assertSee('30 / 60 / 90 dias')
        ->assertDontSee('Nova condição'));
});

it('cadastra condição a prazo a partir dos dias digitados', function (): void {
    Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(PaymentTermsIndex::class)
        ->set('code', 'boleto-45-75')
        ->set('name', 'Boleto 45/75 dias')
        ->set('installmentDays', '45, 75')
        ->set('requiresCredit', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Condição BOLETO-45-75 cadastrada.')
        ->assertSet('code', '')
        ->assertSee('Boleto 45/75 dias'));

    expect(($this->term)('BOLETO-45-75'))->toBe(['Boleto 45/75 dias', [45, 75], true, true]);
});

it('edita condição existente, inclusive inativando', function (): void {
    $id = $this->tenant->system(static fn (): string => PaymentTerm::query()->where('code', '28D')->value('id'));

    Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(PaymentTermsIndex::class)
        ->call('edit', $id)
        ->assertSet('editingId', $id)
        ->assertSet('installmentDays', '28')
        ->assertSet('requiresCredit', true)
        ->set('name', '28 dias (boleto)')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Condição 28D atualizada.')
        ->assertSet('editingId', null));

    expect(($this->term)('28D'))->toBe(['28 dias (boleto)', [28], true, false]);
});

it('mostra erros de formato e de regra nos campos', function (): void {
    Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(PaymentTermsIndex::class)
        ->set('code', 'PIX')
        ->set('name', 'PIX')
        ->set('installmentDays', '30 dias')
        ->call('save')
        ->assertHasErrors(['installmentDays' => 'Informe os dias de vencimento separados por barra, por exemplo 30/60/90 (0 para à vista).'])
        ->set('installmentDays', '30')
        ->set('requiresCredit', false)
        ->call('save')
        ->assertHasErrors(['installmentDays' => 'Condição à vista tem uma única parcela no dia 0. Para vencimentos posteriores, marque como a prazo.'])
        ->set('installmentDays', '0')
        ->set('code', 'avista')
        ->call('save')
        ->assertHasErrors(['code' => 'Já existe uma condição de pagamento com o código AVISTA.'])
        ->set('code', 'PIX À VISTA')
        ->call('save')
        ->assertHasErrors(['code' => 'regex']));

    expect(($this->term)('PIX'))->toBeNull();
});

it('trata condição de outro tenant ou identificador malformado como inexistente', function (): void {
    $other = TenantScenario::create();
    $foreign = $other->actingAs($other->ownerMembership, static fn (): PaymentTerm => app(SavePaymentTerm::class)
        ->handle(null, new PaymentTermData('35DD', 'Boleto 35 dias', [35], true)));

    Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(PaymentTermsIndex::class)
        ->call('edit', $foreign->id)
        ->assertNotFound());

    Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(PaymentTermsIndex::class)
        ->call('edit', '35DD')
        ->assertNotFound());

    expect(($this->term)('35DD', $other))->toBe(['Boleto 35 dias', [35], true, true]);
});

it('protege a condição em edição contra alteração pelo cliente', function (): void {
    $id = $this->tenant->system(static fn (): string => PaymentTerm::query()->where('code', '28D')->value('id'));

    Fx::asMember($this->tenant, $this->manager, function () use ($id): void {
        $component = Livewire::test(PaymentTermsIndex::class);

        expect(fn () => $component->set('editingId', $id))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('refaz a autorização ao salvar com perfil apenas de consulta', function (): void {
    Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(PaymentTermsIndex::class)
        ->set('code', 'PIX')
        ->set('name', 'PIX')
        ->set('installmentDays', '0')
        ->call('save')
        ->assertForbidden());

    expect(($this->term)('PIX'))->toBeNull();
});
