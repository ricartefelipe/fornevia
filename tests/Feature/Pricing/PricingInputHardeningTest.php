<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Pricing\NegotiatedPricesIndex;
use App\Livewire\Staff\Pricing\PriceListEdit;
use App\Livewire\Staff\Pricing\PriceListsIndex;
use App\Livewire\Staff\Pricing\PriceSimulator;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier;
use Livewire\Livewire;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Entradas das telas de preço: quantidades em pt-BR ("1.000" é mil),
| valores e datas fora do intervalo suportado viram erro de campo (nunca
| erro 500) e a margem mínima interna só vai ao navegador de quem a altera.
*/

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $this->company = Fx::company($this->tenant, 'Casa & Obra Materiais');
    Tests\Integration\Ordering\Staff\StaffOrderFixtures::assignRep($this->tenant, $this->company, $this->rep);
    $this->sku = Fx::sku($this->tenant, 'PAR-6', 'Parafuso sextavado 6 mm');
});

it('interpreta "1.000" como mil nas faixas da tabela', function (): void {
    $list = Fx::priceList($this->tenant, 'PADRAO', activate: false);

    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $list])
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', '0,85')
        ->call('addTier')
        ->set('tiers.0.min', '1.000')
        ->set('tiers.0.price', '0,70')
        ->call('saveItem')
        ->assertHasNoErrors());

    $tiers = $this->tenant->system(static fn (): array => PriceTier::query()->get()
        ->map(static fn (PriceTier $t): string => $t->min_quantity->toDatabase())->all());

    expect($tiers)->toBe(['1000.000']);
});

it('recusa como erro de campo faixa e preço acima do limite suportado', function (): void {
    $list = Fx::priceList($this->tenant, 'PADRAO', activate: false);

    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $list])
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', '99999999999999,99')
        ->call('addTier')
        ->set('tiers.0.min', '1000000000000000')
        ->set('tiers.0.price', '0,70')
        ->call('saveItem')
        ->assertHasErrors(['unitPrice', 'tiers.0.min'])
        ->assertSee('Valor acima do limite suportado.')
        ->assertSee('Quantidade acima do limite suportado.'));

    expect($this->tenant->system(static fn (): int => PriceListItem::query()->count()))->toBe(0);
});

it('simula com quantidade digitada com separador de milhar', function (): void {
    $list = Fx::priceList($this->tenant, 'PADRAO');
    Fx::item($this->tenant, $list, $this->sku, '0,85', [['1000', '0,70']]);

    Fx::asMember($this->tenant, $this->rep, fn () => Livewire::test(PriceSimulator::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->set('quantity', '1.000')
        ->call('simulate')
        ->assertHasNoErrors()
        ->assertSet('result.unit_price', 'R$ 0,70')
        ->assertSet('result.line_total', 'R$ 700,00'));
});

it('recusa no simulador quantidade cujo total excede o limite, sem erro 500', function (): void {
    $list = Fx::priceList($this->tenant, 'PADRAO');
    Fx::item($this->tenant, $list, $this->sku, '9.999,99');

    Fx::asMember($this->tenant, $this->rep, fn () => Livewire::test(PriceSimulator::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->set('quantity', '999999999999999')
        ->call('simulate')
        ->assertHasErrors(['quantity'])
        ->assertSet('result', null));
});

it('recusa datas fora do intervalo comercial nas telas de preço', function (string $date): void {
    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListsIndex::class)
        ->set('code', 'TAB-ANTIGA')
        ->set('name', 'Tabela antiga')
        ->set('scope', 'default')
        ->set('priority', '0')
        ->set('validFrom', $date)
        ->call('create')
        ->assertHasErrors(['validFrom']));

    Fx::asMember($this->tenant, $this->rep, fn () => Livewire::test(PriceSimulator::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->set('date', $date)
        ->call('simulate')
        ->assertHasErrors(['date']));

    expect($this->tenant->system(static fn (): int => PriceList::query()->where('code', 'TAB-ANTIGA')->count()))->toBe(0);
})->with([
    'ano zero' => ['0000-01-01'],
    'antes de 2000' => ['1999-12-31'],
    'após 2099' => ['2100-01-01'],
]);

it('recusa vigência de condição negociada com ano fora do intervalo', function (): void {
    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(NegotiatedPricesIndex::class)
        ->set('buyerCompanyId', $this->company->id)
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', '0,80')
        ->set('validFrom', '0000-01-01')
        ->call('create')
        ->assertHasErrors(['validFrom']));
});

it('envia a margem mínima padrão apenas a quem pode alterá-la', function (): void {
    Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(PriceListsIndex::class)
        ->assertSet('minMargin', '')
        ->assertDontSee('Margem mínima padrão'));

    Fx::asMember($this->tenant, $this->rep, fn () => Livewire::test(PriceListsIndex::class)
        ->assertSet('minMargin', ''));

    Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListsIndex::class)
        ->assertSet('minMargin', '10')
        ->assertSee('Margem mínima padrão'));
});

it('recusa o preço digitado quando a embalagem do SKU mudou com o formulário aberto', function (): void {
    $list = Fx::priceList($this->tenant, 'PADRAO', activate: false);
    $box = Fx::unit($this->tenant, 'CX');

    $form = Fx::asMember($this->tenant, $this->manager, fn () => Livewire::test(PriceListEdit::class, ['priceList' => $list])
        ->call('selectSku', $this->sku->id)
        ->set('unitPrice', '0,85'));

    // Outra pessoa muda a unidade de venda do SKU (ainda sem preço).
    $this->tenant->system(fn () => App\Models\Catalog\Sku::query()->whereKey($this->sku->id)
        ->update(['sales_unit_id' => $box->id, 'conversion_factor' => '100']));

    Fx::asMember($this->tenant, $this->manager, fn () => $form->call('saveItem')
        ->assertHasErrors(['unitPrice'])
        ->assertSee('Confira o preço por CX'));

    expect($this->tenant->system(static fn (): int => PriceListItem::query()->count()))->toBe(0);
});
