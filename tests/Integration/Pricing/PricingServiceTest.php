<?php

declare(strict_types=1);

use App\Actions\Pricing\ArchivePriceList;
use App\Actions\Pricing\RevokeNegotiatedPrice;
use App\Actions\Pricing\SaveNegotiatedPrice;
use App\Actions\Pricing\SetPriceListItem;
use App\Domain\Pricing\Exceptions\NegotiatedPriceOverlap;
use App\Domain\Pricing\PriceSource;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Models\Pricing\NegotiatedPrice;
use App\Services\Pricing\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| PricingService sobre tabelas reais (PostgreSQL com RLS): precedência,
| desempates, vigência no fuso de negócio, faixas por volume, visibilidade
| no contexto de comprador, cache por versão de preços e sobreposição de
| condições negociadas.
*/

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->staff = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->sku = Fx::sku($this->tenant, 'CAF-500', 'Café torrado e moído 500 g');
});

it('aplica a precedência negociada, empresa, grupo e padrão, recuando a cada fonte retirada', function (): void {
    $group = Fx::group($this->tenant, 'VAREJO', 'Varejo alimentar');
    $company = Fx::company($this->tenant, 'Mercado Bom Preço', $group);

    $default = Fx::priceList($this->tenant, 'PADRAO');
    $groupList = Fx::priceList($this->tenant, 'VAREJO-26', PriceListScope::Group, $group->id);
    $customerList = Fx::priceList($this->tenant, 'BOMPRECO', PriceListScope::Customer, $company->id);
    Fx::item($this->tenant, $default, $this->sku, '25,90');
    Fx::item($this->tenant, $groupList, $this->sku, '24,50');
    Fx::item($this->tenant, $customerList, $this->sku, '23,80');
    $negotiated = Fx::negotiated($this->tenant, $company, $this->sku, '22,40', '2026-11-01');

    $resolve = fn (): array => [
        Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->source,
        Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->unitPrice->cents,
    ];

    expect($resolve())->toBe([PriceSource::Negotiated, 2240]);

    $this->tenant->actingAs($this->tenant->ownerMembership, fn () => app(RevokeNegotiatedPrice::class)->handle($negotiated, 'Contrato encerrado'));
    expect($resolve())->toBe([PriceSource::CustomerList, 2380]);

    $this->tenant->actingAs($this->tenant->ownerMembership, fn () => app(ArchivePriceList::class)->handle($customerList));
    expect($resolve())->toBe([PriceSource::GroupList, 2450]);

    $this->tenant->actingAs($this->tenant->ownerMembership, fn () => app(ArchivePriceList::class)->handle($groupList));
    expect($resolve())->toBe([PriceSource::DefaultList, 2590]);

    $this->tenant->actingAs($this->tenant->ownerMembership, fn () => app(ArchivePriceList::class)->handle($default));
    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1'))->toBeNull();
});

it('ignora tabelas em rascunho, de outro grupo e de outra empresa', function (): void {
    $group = Fx::group($this->tenant, 'VAREJO', 'Varejo alimentar');
    $otherGroup = Fx::group($this->tenant, 'FOOD', 'Food service');
    $company = Fx::company($this->tenant, 'Mercado Bom Preço', $group);
    $other = Fx::company($this->tenant, 'Restaurante Sabor da Serra', $otherGroup);

    Fx::item($this->tenant, Fx::priceList($this->tenant, 'PADRAO'), $this->sku, '25,90');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'RASCUNHO', priority: 99, activate: false), $this->sku, '10,00');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'FOOD-26', PriceListScope::Group, $otherGroup->id), $this->sku, '11,00');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'SABOR', PriceListScope::Customer, $other->id), $this->sku, '12,00');

    $resolved = Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1');

    expect($resolved?->source)->toBe(PriceSource::DefaultList)
        ->and($resolved?->unitPrice->cents)->toBe(2590);
});

it('desempata no mesmo nível por prioridade, início de vigência e ordem de criação', function (): void {
    $company = Fx::company($this->tenant, 'Empório Vale Verde');

    $low = Fx::priceList($this->tenant, 'P-BAIXA', priority: 5, from: '2026-10-01');
    $high = Fx::priceList($this->tenant, 'P-ALTA', priority: 10, from: '2026-01-01');
    Fx::item($this->tenant, $low, $this->sku, '20,00');
    Fx::item($this->tenant, $high, $this->sku, '21,00');

    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->referenceId)->toBe($high->id);

    // Mesma prioridade: vence o início de vigência mais recente.
    $recent = Fx::priceList($this->tenant, 'P-RECENTE', priority: 10, from: '2026-06-01');
    Fx::item($this->tenant, $recent, $this->sku, '22,00');

    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->referenceId)->toBe($recent->id);

    // Mesma prioridade e mesmo início: vence a tabela criada primeiro (UUIDv7).
    usleep(2_000);
    $twin = Fx::priceList($this->tenant, 'P-GEMEA', priority: 10, from: '2026-06-01');
    Fx::item($this->tenant, $twin, $this->sku, '19,00');

    expect(strcmp($recent->id, $twin->id))->toBeLessThan(0)
        ->and(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->referenceId)->toBe($recent->id);
});

it('respeita as bordas de vigência pela data do fuso de negócio, não pela data UTC', function (): void {
    $company = Fx::company($this->tenant, 'Armazém Santa Luzia');
    $november = Fx::priceList($this->tenant, 'NOV-26', from: '2026-11-01', until: '2026-11-30');
    $december = Fx::priceList($this->tenant, 'DEZ-26', from: '2026-12-01');
    Fx::item($this->tenant, $november, $this->sku, '25,00');
    Fx::item($this->tenant, $december, $this->sku, '27,00');

    // 01/12 02:30 UTC = 30/11 23:30 em São Paulo: ainda vale a tabela de novembro.
    $clock = Fx::freezeClock('2026-12-01T02:30:00Z');
    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->referenceId)->toBe($november->id);

    // 01/12 03:00 UTC = 01/12 00:00 em São Paulo: passa a valer a de dezembro.
    $clock->advance('PT30M');
    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->referenceId)->toBe($december->id);

    // Datas explícitas também são inclusivas nas duas pontas.
    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1', '2026-11-30')?->referenceId)->toBe($november->id)
        ->and(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1', '2026-11-01')?->referenceId)->toBe($november->id)
        ->and(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1', '2026-10-31'))->toBeNull();
});

it('aplica a faixa atingida a todas as unidades da linha, inclusive com quantidades fracionadas', function (): void {
    $company = Fx::company($this->tenant, 'Padaria Trigo de Ouro');
    $flour = Fx::sku($this->tenant, 'FAR-KG', 'Farinha de trigo especial', 'KG', 3);
    $list = Fx::priceList($this->tenant, 'PADRAO');
    Fx::item($this->tenant, $list, $flour, '10,00', [['10', '9,00'], ['50', '8,00']]);

    $at = fn (string $quantity): array => [
        Fx::resolve($this->tenant, $this->staff, $company, $flour, $quantity)?->unitPrice->cents,
        Fx::resolve($this->tenant, $this->staff, $company, $flour, $quantity)?->tier?->minQuantity->toDatabase(),
    ];

    expect($at('9,999'))->toBe([1000, null])
        ->and($at('10'))->toBe([900, '10.000'])
        ->and($at('49,5'))->toBe([900, '10.000'])
        ->and($at('50'))->toBe([800, '50.000'])
        ->and($at('120'))->toBe([800, '50.000']);

    $unit = Fx::resolve($this->tenant, $this->staff, $company, $flour, '12,5');

    // 12,5 kg a R$ 9,00 (faixa de 10 kg para todas as unidades) = R$ 112,50.
    expect($unit?->unitPrice->times(Quantity::of('12,5'))->cents)->toBe(11250);
});

it('mostra ao comprador, no próprio contexto, somente as tabelas e condições aplicáveis à sua empresa', function (): void {
    $group = Fx::group($this->tenant, 'VAREJO', 'Varejo alimentar');
    $otherGroup = Fx::group($this->tenant, 'FOOD', 'Food service');
    $company = Fx::company($this->tenant, 'Mercado Bom Preço', $group);
    $other = Fx::company($this->tenant, 'Restaurante Sabor da Serra', $otherGroup);
    $buyer = $this->tenant->addBuyerUser($company);

    Fx::item($this->tenant, Fx::priceList($this->tenant, 'PADRAO'), $this->sku, '25,90');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'RASCUNHO', priority: 99, activate: false), $this->sku, '10,00');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'VAREJO-26', PriceListScope::Group, $group->id), $this->sku, '24,50');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'FOOD-26', PriceListScope::Group, $otherGroup->id), $this->sku, '21,00');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'BOMPRECO', PriceListScope::Customer, $company->id), $this->sku, '23,80');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'SABOR', PriceListScope::Customer, $other->id), $this->sku, '20,00');
    Fx::negotiated($this->tenant, $other, $this->sku, '18,00', '2026-11-01');

    $visible = $this->tenant->actingAs($buyer, fn (): array => [
        'lists' => DB::table('price_lists')->orderBy('code')->pluck('code')->all(),
        'items' => DB::table('price_list_items')->count(),
        'tiers' => DB::table('price_tiers')->count(),
        'negotiated' => DB::table('negotiated_prices')->count(),
        'other_company' => DB::table('buyer_companies')->where('id', $other->id)->count(),
    ]);

    expect($visible)->toBe([
        'lists' => ['BOMPRECO', 'PADRAO', 'VAREJO-26'],
        'items' => 3,
        'tiers' => 0,
        'negotiated' => 0,
        'other_company' => 0,
    ]);

    // Pelo contexto do comprador, o preço de outra empresa é recusado: o
    // cálculo veria só a tabela padrão e gravaria esse resultado no cache
    // compartilhado com a equipe.
    expect(fn () => Fx::resolve($this->tenant, $buyer, $other, $this->sku, '1'))->toThrow(LogicException::class);

    $forOwn = Fx::resolve($this->tenant, $buyer, $company, $this->sku, '1');

    expect($forOwn?->source)->toBe(PriceSource::CustomerList)
        ->and($forOwn?->unitPrice->cents)->toBe(2380);

    // A equipe enxerga as condições da outra empresa, na mesma data (cache
    // sem resultado calculado pelo comprador).
    expect(Fx::resolve($this->tenant, $this->staff, $other, $this->sku, '1')?->unitPrice->cents)->toBe(1800);
});

it('não permite ao comprador alterar tabelas, itens, faixas ou condições negociadas', function (): void {
    $company = Fx::company($this->tenant, 'Mercado Bom Preço');
    $buyer = $this->tenant->addBuyerUser($company, SystemRole::BuyerAdmin);
    $list = Fx::priceList($this->tenant, 'PADRAO');
    Fx::item($this->tenant, $list, $this->sku, '25,90', [['10', '24,00']]);
    Fx::negotiated($this->tenant, $company, $this->sku, '22,00', '2026-11-01');

    $affected = $this->tenant->actingAs($buyer, fn (): array => [
        'lists' => DB::table('price_lists')->update(['priority' => 999]),
        'items' => DB::table('price_list_items')->update(['unit_price_cents' => 1]),
        'tiers' => DB::table('price_tiers')->update(['unit_price_cents' => 1]),
        'negotiated' => DB::table('negotiated_prices')->update(['unit_price_cents' => 1]),
    ]);

    expect($affected)->toBe(['lists' => 0, 'items' => 0, 'tiers' => 0, 'negotiated' => 0])
        ->and(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->unitPrice->cents)->toBe(2200);
});

it('usa o cache por versão de preços e o invalida quando uma Action altera o preço', function (): void {
    $company = Fx::company($this->tenant, 'Mercearia São Jorge');
    $list = Fx::priceList($this->tenant, 'PADRAO');
    $item = Fx::item($this->tenant, $list, $this->sku, '25,90');
    $version = Fx::version($this->tenant);

    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->unitPrice->cents)->toBe(2590);

    // Alteração direta sem nova versão: o resultado em cache continua sendo servido.
    $this->tenant->system(fn () => DB::table('price_list_items')->where('id', $item->id)->update(['unit_price_cents' => 1990]));
    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->unitPrice->cents)->toBe(2590);

    // Pela Action, a versão muda na mesma transação e o cache antigo deixa de ser lido.
    $this->tenant->actingAs($this->staff, fn () => app(SetPriceListItem::class)->handle($list, $this->sku, Money::parse('24,70')));

    $resolved = Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1');

    expect(Fx::version($this->tenant))->toBe($version + 1)
        ->and($resolved?->unitPrice->cents)->toBe(2470)
        ->and($resolved?->version)->toBe($version + 1);
});

it('considera o grupo atual da empresa na chave do cache de preços', function (): void {
    $retail = Fx::group($this->tenant, 'VAREJO', 'Varejo alimentar');
    $food = Fx::group($this->tenant, 'FOOD', 'Food service');
    $company = Fx::company($this->tenant, 'Mercado Bom Preço', $retail);
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'VAREJO-26', PriceListScope::Group, $retail->id), $this->sku, '24,50');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'FOOD-26', PriceListScope::Group, $food->id), $this->sku, '23,00');

    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->unitPrice->cents)->toBe(2450);

    $moved = $this->tenant->system(function () use ($company, $food) {
        $company->forceFill(['customer_group_id' => $food->id])->save();

        return $company->fresh();
    });

    expect(Fx::resolve($this->tenant, $this->staff, $moved, $this->sku, '1')?->unitPrice->cents)->toBe(2300);
});

it('resolve vários SKUs em lote, com null para SKU sem preço vigente', function (): void {
    $company = Fx::company($this->tenant, 'Distribuidora de Bairro Ipiranga');
    $sugar = Fx::sku($this->tenant, 'ACU-1KG', 'Açúcar refinado 1 kg');
    $rice = Fx::sku($this->tenant, 'ARR-5KG', 'Arroz tipo 1 5 kg');
    $list = Fx::priceList($this->tenant, 'PADRAO');
    Fx::item($this->tenant, $list, $this->sku, '25,90');
    Fx::item($this->tenant, $list, $sugar, '4,79', [['24', '4,49']]);

    $result = $this->tenant->actingAs($this->staff, fn (): array => app(PricingService::class)->resolve($company, [
        $this->sku->id => Quantity::of('2'),
        $sugar->id => Quantity::of('24'),
        $rice->id => Quantity::of('1'),
    ], CarbonImmutable::parse('2026-11-17')));

    expect($result[$this->sku->id]?->unitPrice->cents)->toBe(2590)
        ->and($result[$sugar->id]?->unitPrice->cents)->toBe(449)
        ->and($result[$rice->id])->toBeNull();
});

it('recusa pelo banco a sobreposição de condições negociadas e mantém a transação utilizável', function (): void {
    $company = Fx::company($this->tenant, 'Mercado Bom Preço');
    Fx::negotiated($this->tenant, $company, $this->sku, '22,00', '2026-11-01', '2026-11-30');

    $outcome = $this->tenant->actingAs($this->staff, function () use ($company): array {
        $save = app(SaveNegotiatedPrice::class);

        try {
            $save->handle($company, $this->sku, Money::parse('21,00'), CarbonImmutable::parse('2026-11-30'), null);
            $refused = null;
        } catch (NegotiatedPriceOverlap $e) {
            $refused = $e->errorCode();
        }

        // Período adjacente, na mesma transação, é aceito.
        $next = $save->handle($company, $this->sku, Money::parse('21,00'), CarbonImmutable::parse('2026-12-01'), null);

        return [$refused, $next->valid_from->toDateString(), NegotiatedPrice::query()->count()];
    });

    expect($outcome)->toBe(['negotiated_price_overlap', '2026-12-01', 2])
        ->and(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1', '2026-11-30')?->unitPrice->cents)->toBe(2200)
        ->and(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1', '2026-12-01')?->unitPrice->cents)->toBe(2100);
});

it('isola preços entre tenants mesmo com códigos e SKUs equivalentes', function (): void {
    $other = TenantScenario::create();
    $otherSku = Fx::sku($other, 'CAF-500', 'Café torrado e moído 500 g');
    $company = Fx::company($this->tenant, 'Mercado Bom Preço');
    $otherCompany = Fx::company($other, 'Mercado Bom Preço');

    Fx::item($this->tenant, Fx::priceList($this->tenant, 'PADRAO'), $this->sku, '25,90');
    Fx::item($other, Fx::priceList($other, 'PADRAO'), $otherSku, '19,90');

    expect(Fx::resolve($this->tenant, $this->staff, $company, $this->sku, '1')?->unitPrice->cents)->toBe(2590)
        ->and(Fx::resolve($other, $other->ownerMembership, $otherCompany, $otherSku, '1')?->unitPrice->cents)->toBe(1990)
        // SKU de outro tenant consultado a partir deste tenant: nenhuma tabela visível.
        ->and(Fx::resolve($this->tenant, $this->staff, $company, $otherSku, '1'))->toBeNull();
});
