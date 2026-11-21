<?php

declare(strict_types=1);

use App\Actions\Pricing\RevokeNegotiatedPrice;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Pricing\NegotiatedPricesIndex;
use App\Models\Audit\AuditLog;
use App\Models\Pricing\NegotiatedPrice;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $this->company = Fx::company($this->tenant, 'Supermercado Vila Nova');
    $this->sku = Fx::sku($this->tenant, 'CAF-250', 'Café extraforte 250 g');

    $this->stored = fn (?TenantScenario $tenant = null): array => ($tenant ?? $this->tenant)->system(static fn (): array => NegotiatedPrice::query()
        ->orderBy('valid_from')
        ->get()
        ->map(static fn (NegotiatedPrice $p): array => [$p->buyer_company_id, $p->unit_price_cents->cents, $p->valid_from->toDateString(), $p->valid_until?->toDateString(), $p->status->value])
        ->all());
});

it('registra condição negociada pela tela, com o usuário atual como aprovador', function (): void {
    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->assertSet('validFrom', '2026-11-17')
        ->set('companySearch', 'vila nova')
        ->assertSee('Supermercado Vila Nova')
        ->set('buyerCompanyId', $this->company->id)
        ->set('skuSearch', 'CAF')
        ->assertSee('Café extraforte 250 g')
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', '9,49')
        ->set('validFrom', '2026-12-01')
        ->set('validUntil', '2027-05-31')
        ->set('notes', 'Contrato semestral de abastecimento')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('message', 'Condição negociada registrada para Supermercado Vila Nova no SKU CAF-250.')
        ->assertSet('selectedSkuId', null)
        ->set('statusFilter', 'all')
        ->assertSee('R$ 9,49')
        ->assertSee('Contrato semestral de abastecimento'));

    $stored = $this->tenant->system(static fn (): NegotiatedPrice => NegotiatedPrice::query()->sole());

    expect(($this->stored)())->toBe([[$this->company->id, 949, '2026-12-01', '2027-05-31', 'active']])
        ->and($stored->approved_by_user_id)->toBe($this->manager->user_id)
        ->and($stored->notes)->toBe('Contrato semestral de abastecimento');
});

it('mostra a recusa de sobreposição no campo de vigência', function (): void {
    Fx::negotiated($this->tenant, $this->company, $this->sku, '9,49', '2026-12-01', '2026-12-31');

    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', '9,19')
        ->set('validFrom', '2026-12-15')
        ->call('create')
        ->assertHasErrors(['validFrom'])
        ->assertSee('Já existe condição negociada ativa para esta empresa e este SKU de 01/12/2026 a 31/12/2026')
        ->assertSet('message', null));

    expect(($this->stored)())->toHaveCount(1);
});

it('valida os campos antes de registrar', function (): void {
    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->call('create')
        ->assertHasErrors(['buyerCompanyId' => 'required', 'unitPrice' => 'required'])
        ->set('buyerCompanyId', $this->company->id)
        ->set('unitPrice', '9,49')
        ->call('create')
        ->assertHasErrors(['skuSearch'])
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', 'nove reais')
        ->call('create')
        ->assertHasErrors(['unitPrice'])
        ->set('unitPrice', '0,00')
        ->call('create')
        ->assertHasErrors(['unitPrice' => 'O preço negociado deve ser maior que zero.'])
        ->set('unitPrice', '9,49')
        ->set('validFrom', '2026-12-10')
        ->set('validUntil', '2026-12-01')
        ->call('create')
        ->assertHasErrors(['validUntil' => 'after_or_equal']));

    expect(($this->stored)())->toBe([]);
});

it('revoga com justificativa e exige o motivo', function (): void {
    $price = Fx::negotiated($this->tenant, $this->company, $this->sku, '9,49', '2026-12-01');

    Fx::asMember($this->tenant, $this->manager, static fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->assertSee('R$ 9,49')
        ->call('revoke', $price->id)
        ->assertHasErrors(['revokeReason'])
        ->set('revokeReason', 'Fornecedor reajustou o custo')
        ->call('revoke', $price->id)
        ->assertHasNoErrors()
        ->assertSet('message', 'Condição negociada revogada.')
        ->assertSet('revokeReason', '')
        ->assertSee('Nenhuma condição negociada'));

    $log = $this->tenant->system(static fn (): AuditLog => AuditLog::query()->where('action', 'negotiated_price.revoked')->sole());

    expect(($this->stored)())->toBe([[$this->company->id, 949, '2026-12-01', null, 'revoked']])
        ->and($log->reason)->toBe('Fornecedor reajustou o custo');
});

it('filtra por empresa e por situação', function (): void {
    $other = Fx::company($this->tenant, 'Mercearia Recanto');
    $otherSku = Fx::sku($this->tenant, 'ACU-5KG', 'Açúcar cristal 5 kg');
    Fx::negotiated($this->tenant, $this->company, $this->sku, '9,49', '2026-12-01');
    $revoked = Fx::negotiated($this->tenant, $other, $otherSku, '21,90', '2026-12-01');
    $this->tenant->actingAs($this->manager, static fn () => app(RevokeNegotiatedPrice::class)->handle($revoked, 'Cliente encerrou o contrato'));

    Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->assertSee('CAF-250')
        ->assertDontSee('ACU-5KG')
        ->set('statusFilter', 'revoked')
        ->assertSee('ACU-5KG')
        ->assertDontSee('CAF-250')
        ->set('statusFilter', 'all')
        ->set('companyFilter', $other->id)
        ->assertSee('ACU-5KG')
        ->assertDontSee('CAF-250')
        ->set('companyFilter', 'nao-e-uuid')
        ->assertSee('ACU-5KG')
        ->assertSee('CAF-250'));
});

it('trata empresa, SKU e condição de outro tenant como inexistentes', function (string $case): void {
    $other = TenantScenario::create();
    $foreignCompany = Fx::company($other, 'Supermercado Vila Nova');
    $foreignSku = Fx::sku($other, 'CAF-250', 'Café extraforte 250 g');
    $foreignPrice = Fx::negotiated($other, $foreignCompany, $foreignSku, '8,99', '2026-12-01');

    Fx::asMember($this->tenant, $this->manager, fn () => match ($case) {
        'empresa' => Livewire::test(NegotiatedPricesIndex::class)
            ->set('buyerCompanyId', $foreignCompany->id)
            ->call('selectSku', $this->sku->id)
            ->set('unitPrice', '1,00')
            ->call('create')
            ->assertNotFound(),
        'sku' => Livewire::test(NegotiatedPricesIndex::class)->call('selectSku', $foreignSku->id)->assertNotFound(),
        'revogação' => Livewire::test(NegotiatedPricesIndex::class)
            ->set('revokeReason', 'Tentativa indevida')
            ->call('revoke', $foreignPrice->id)
            ->assertNotFound(),
        'malformado' => Livewire::test(NegotiatedPricesIndex::class)->call('revoke', 'CAF-250')->assertNotFound(),
    });

    expect(($this->stored)())->toBe([])
        ->and(($this->stored)($other))->toBe([[$foreignCompany->id, 899, '2026-12-01', null, 'active']]);
})->with(['empresa', 'sku', 'revogação', 'malformado']);

it('protege o SKU selecionado contra alteração pelo cliente', function (): void {
    Fx::asMember($this->tenant, $this->manager, function (): void {
        $component = Livewire::test(NegotiatedPricesIndex::class);

        expect(fn () => $component->set('selectedSkuId', $this->sku->id))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('refaz a autorização nas ações de perfil apenas de consulta', function (): void {
    $price = Fx::negotiated($this->tenant, $this->company, $this->sku, '9,49', '2026-12-01');

    Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->assertDontSee('Registrar condição')
        ->set('revokeReason', 'Revogação sem permissão')
        ->call('revoke', $price->id)
        ->assertForbidden());

    Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', '1,00')
        ->set('validFrom', '2027-01-01')
        ->call('create')
        ->assertForbidden());

    expect(($this->stored)())->toBe([[$this->company->id, 949, '2026-12-01', null, 'active']]);
});
