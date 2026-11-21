<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Livewire\Staff\Pricing\PriceSimulator;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $this->group = Fx::group($this->tenant, 'CONSTRUCAO', 'Lojas de material de construção');
    $this->company = Fx::company($this->tenant, 'Casa & Obra Materiais', $this->group);
    $this->sku = Fx::sku($this->tenant, 'CIM-50', 'Cimento CP II 50 kg', 'PC');

    $this->default = Fx::priceList($this->tenant, 'PADRAO');
    $this->groupList = Fx::priceList($this->tenant, 'CONSTRUCAO-26', PriceListScope::Group, $this->group->id);
    Fx::item($this->tenant, $this->default, $this->sku, '39,90', [['50', '37,90']]);
    Fx::item($this->tenant, $this->groupList, $this->sku, '38,50', [['20', '36,80'], ['100', '35,50']]);
});

it('mostra o preço resolvido, a origem, a faixa aplicada e todos os candidatos', function (): void {
    Fx::asMember($this->tenant, $this->rep, fn () => Livewire::test(PriceSimulator::class)
        ->assertSet('date', '2026-11-17')
        ->assertSee('Nenhuma simulação')
        ->set('companySearch', 'casa')
        ->set('buyerCompanyId', $this->company->id)
        ->set('skuSearch', 'cimento')
        ->assertSee('CIM-50')
        ->call('selectSku', $this->sku->id)
        ->set('quantity', '60')
        ->call('simulate')
        ->assertHasNoErrors()
        ->assertSet('result.available', true)
        ->assertSet('result.unit_price', 'R$ 36,80')
        ->assertSet('result.source_label', 'Tabela do grupo')
        ->assertSet('result.tier', '20')
        ->assertSet('result.line_total', 'R$ 2.208,00')
        ->assertSet('result.candidates.0.reference_id', $this->groupList->id)
        ->assertSet('result.candidates.0.winner', true)
        ->assertSet('result.candidates.1.reference_id', $this->default->id)
        ->assertSet('result.candidates.1.unit_price', 'R$ 37,90')
        ->assertSet('result.candidates.1.explanation', 'Tabela do grupo tem precedência sobre Tabela padrão.')
        ->assertSee('Candidatos considerados')
        ->assertSee('Vencedor')
        ->assertSee('Tabela CONSTRUCAO-26 · Tabela CONSTRUCAO-26'));
});

it('informa ausência de preço vigente na data escolhida', function (): void {
    Fx::asMember($this->tenant, $this->rep, fn () => Livewire::test(PriceSimulator::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->set('date', '2025-12-31')
        ->call('simulate')
        ->assertSet('result.available', false)
        ->assertSee('Não há preço vigente para esta empresa, este SKU e esta data.')
        ->assertSee('Nenhum candidato vigente'));
});

it('valida empresa, SKU, quantidade e data', function (): void {
    Fx::asMember($this->tenant, $this->rep, fn () => Livewire::test(PriceSimulator::class)
        ->set('date', '')
        ->call('simulate')
        ->assertHasErrors(['buyerCompanyId' => 'required', 'date' => 'required'])
        ->set('buyerCompanyId', $this->company->id)
        ->set('date', '2026-11-17')
        ->call('simulate')
        ->assertHasErrors(['skuSearch'])
        ->call('selectSku', $this->sku->id)
        ->set('quantity', '1,2345')
        ->call('simulate')
        ->assertHasErrors(['quantity'])
        ->set('quantity', '0')
        ->call('simulate')
        ->assertHasErrors(['quantity' => 'A quantidade deve ser maior que zero.'])
        ->assertSet('result', null));
});

it('trata empresa ou SKU de outro tenant como inexistentes', function (string $case): void {
    $other = TenantScenario::create();
    $foreignCompany = Fx::company($other, 'Casa & Obra Materiais');
    $foreignSku = Fx::sku($other, 'CIM-50', 'Cimento CP II 50 kg', 'PC');

    Fx::asMember($this->tenant, $this->rep, fn () => match ($case) {
        'empresa' => Livewire::test(PriceSimulator::class)
            ->set('buyerCompanyId', $foreignCompany->id)
            ->call('selectSku', $this->sku->id)
            ->call('simulate')
            ->assertNotFound(),
        'sku' => Livewire::test(PriceSimulator::class)->call('selectSku', $foreignSku->id)->assertNotFound(),
        'malformado' => Livewire::test(PriceSimulator::class)->call('selectSku', 'CIM-50')->assertNotFound(),
    });
})->with(['empresa', 'sku', 'malformado']);

it('protege o SKU selecionado e o resultado contra alteração pelo cliente', function (): void {
    Fx::asMember($this->tenant, $this->rep, function (): void {
        $component = Livewire::test(PriceSimulator::class);

        expect(fn () => $component->set('selectedSkuId', $this->sku->id))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $component->set('result', ['available' => true, 'unit_price' => 'R$ 0,01']))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('nega a simulação a perfil sem pricing.view mesmo chamando a ação diretamente', function (): void {
    $warehouse = $this->tenant->addStaff(SystemRole::Warehouse);

    Fx::asMember($this->tenant, $warehouse, fn () => Livewire::test(PriceSimulator::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->call('simulate')
        ->assertForbidden());
});
