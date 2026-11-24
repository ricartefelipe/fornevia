<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Buyers\BuyerShow;
use App\Livewire\Staff\Buyers\BuyersIndex;
use App\Livewire\Staff\Buyers\CustomerGroupsIndex;
use App\Livewire\Staff\Catalog\PrivateCatalogsIndex;
use App\Livewire\Staff\Credit\CreditAccountShow;
use App\Livewire\Staff\Credit\CreditAccountsIndex;
use App\Livewire\Staff\Dashboard;
use App\Livewire\Staff\Pricing\FreightIndex;
use App\Livewire\Staff\Pricing\NegotiatedPricesIndex;
use App\Livewire\Staff\Pricing\PriceListEdit;
use App\Livewire\Staff\Pricing\PriceListsIndex;
use App\Livewire\Staff\Pricing\PriceSimulator;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Models\Fulfillment\BuyerFreightCondition;
use App\Models\Pricing\NegotiatedPrice;
use App\Models\Pricing\PriceList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Carteira do representante comercial em todas as telas da equipe: só as
| empresas pelas quais responde e os dados ligados a elas (crédito,
| condições negociadas, tabelas e frete por empresa, catálogos atribuídos).
| Os demais papéis veem tudo.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create();
    $this->tenant = $this->scenario->tenant;
    $this->rep = $this->tenant->addStaff(SystemRole::SalesRep);
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $sku = $this->scenario->sku;

    $group = $this->tenant->system(static function (): CustomerGroup {
        $group = new CustomerGroup;
        $group->forceFill(['code' => 'OBRAS', 'name' => 'Construtoras'])->save();

        return $group;
    });
    $this->own = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul', 'customer_group_id' => $group->id]);
    $this->other = $this->tenant->addBuyerCompany(['trade_name' => 'Mercado Bom Preço', 'customer_group_id' => $group->id]);
    Fx::assignRep($this->tenant, $this->own, $this->rep);

    [$this->catalog, $this->ownList, $this->otherList] = $this->tenant->system(function () use ($sku): array {
        $catalog = new PrivateCatalog;
        $catalog->forceFill(['code' => 'OBRAS', 'name' => 'Catálogo de obras'])->save();
        $lists = [];

        foreach ([$this->own, $this->other] as $i => $company) {
            DB::table('credit_accounts')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => $company->tenant_id, 'buyer_company_id' => $company->id,
                'limit_cents' => 100_000 * ($i + 1), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            (new NegotiatedPrice)->forceFill(['buyer_company_id' => $company->id, 'sku_id' => $sku->id, 'unit_price_cents' => 777 + $i, 'valid_from' => '2026-01-01'])->save();
            (new BuyerFreightCondition)->forceFill(['buyer_company_id' => $company->id, 'kind' => 'free'])->save();
            (new PrivateCatalogAssignment)->forceFill(['private_catalog_id' => $catalog->id, 'buyer_company_id' => $company->id])->save();
            $list = new PriceList;
            $list->forceFill(['code' => 'EMPRESA-'.$i, 'name' => 'Tabela de '.$company->trade_name, 'scope' => 'customer', 'buyer_company_id' => $company->id, 'valid_from' => '2026-01-01', 'status' => 'active'])->save();
            $lists[] = $list;
        }

        return [$catalog, ...$lists];
    });

    $this->as = fn ($member, Closure $callback) => Fx::asMember($this->tenant, $member, $callback);
});

it('mostra ao representante só as empresas da carteira nas listas', function (): void {
    ($this->as)($this->rep, function (): void {
        Livewire::test(BuyersIndex::class)->assertSee('Você vê as empresas da sua carteira.')->assertSee('Construtora Serra Azul')->assertDontSee('Mercado Bom Preço');
        Livewire::test(CreditAccountsIndex::class)->assertSee('Você vê as empresas da sua carteira.')->assertSee('Construtora Serra Azul')->assertDontSee('Mercado Bom Preço');
        Livewire::test(NegotiatedPricesIndex::class)->assertSee('Construtora Serra Azul')->assertDontSee('Mercado Bom Preço');
        Livewire::test(PriceListsIndex::class)->assertSee('Tabela de Construtora Serra Azul')->assertDontSee('Tabela de Mercado Bom Preço')->assertSee('Tabela padrão');
        Livewire::test(FreightIndex::class)->assertSee('Construtora Serra Azul')->assertDontSee('Mercado Bom Preço');
        Livewire::test(PrivateCatalogsIndex::class)->call('manage', $this->catalog->id)->assertSee('Construtora Serra Azul')->assertDontSee('Mercado Bom Preço');
        Livewire::test(Dashboard::class)->assertViewHas('buyerCount', 1);
        Livewire::test(CustomerGroupsIndex::class)->assertViewHas('groups', static fn ($groups): bool => $groups->first()->companies_count === 1);
    });
});

it('trata empresa fora da carteira como inexistente nas telas de detalhe', function (): void {
    ($this->as)($this->rep, function (): void {
        Livewire::test(BuyerShow::class, ['buyer' => $this->own])->assertSee('Construtora Serra Azul');
        Livewire::test(CreditAccountShow::class, ['buyer' => $this->own])->assertSee('Construtora Serra Azul');
        Livewire::test(PriceListEdit::class, ['priceList' => $this->ownList])->assertSee('Tabela de Construtora Serra Azul');

        Livewire::test(BuyerShow::class, ['buyer' => $this->other])->assertStatus(404);
        Livewire::test(CreditAccountShow::class, ['buyer' => $this->other])->assertStatus(404);
        Livewire::test(PriceListEdit::class, ['priceList' => $this->otherList])->assertStatus(404);
        Livewire::test(PriceSimulator::class)->set('buyerCompanyId', $this->other->id)->call('selectSku', $this->scenario->sku->id)
            ->call('simulate')->assertStatus(404);
    });
});

it('não recorta para os demais papéis da equipe', function (): void {
    ($this->as)($this->manager, function (): void {
        Livewire::test(BuyersIndex::class)->assertDontSee('Você vê as empresas da sua carteira.')->assertSee('Construtora Serra Azul')->assertSee('Mercado Bom Preço');
        Livewire::test(NegotiatedPricesIndex::class)->assertSee('Mercado Bom Preço');
        Livewire::test(PriceListEdit::class, ['priceList' => $this->otherList])->assertSee('Tabela de Mercado Bom Preço');
        Livewire::test(BuyerShow::class, ['buyer' => $this->other])->assertSee('Mercado Bom Preço');
        Livewire::test(Dashboard::class)->assertViewHas('buyerCount', 2);
    });

    expect($this->tenant->system(static fn (): int => BuyerCompany::query()->count()))->toBe(2);
});
