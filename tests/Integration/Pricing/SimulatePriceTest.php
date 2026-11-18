<?php

declare(strict_types=1);

use App\Actions\Pricing\ArchivePriceList;
use App\Actions\Pricing\SimulatePrice;
use App\Data\Pricing\PriceSimulation;
use App\Data\Pricing\SimulatedCandidate;
use App\Domain\Pricing\Exceptions\InvalidQuantity;
use App\Domain\Pricing\PriceSource;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Models\Identity\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Pricing\PricingFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Fx::freezeClock('2026-11-17T15:00:00Z');
    $this->tenant = TenantScenario::create();
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $this->sku = Fx::sku($this->tenant, 'DET-500', 'Detergente líquido neutro 500 ml');

    $this->simulate = fn (Membership $actor, BuyerCompany $company, Sku $sku, string $quantity, string $date = '2026-11-17'): PriceSimulation => $this->tenant->actingAs(
        $actor,
        static fn (): PriceSimulation => app(SimulatePrice::class)->handle($company, $sku, Quantity::of($quantity), CarbonImmutable::parse($date)),
    );
});

it('explica a precedência listando todos os candidatos vigentes na ordem em que seriam escolhidos', function (): void {
    $group = Fx::group($this->tenant, 'LIMPEZA', 'Revendas de limpeza');
    $company = Fx::company($this->tenant, 'Higiene Total Distribuição', $group);

    $default = Fx::priceList($this->tenant, 'PADRAO');
    $promo = Fx::priceList($this->tenant, 'PROMO', priority: 5, from: '2026-11-01');
    $groupList = Fx::priceList($this->tenant, 'LIMPEZA-26', PriceListScope::Group, $group->id);
    $customer = Fx::priceList($this->tenant, 'HIGIENE', PriceListScope::Customer, $company->id);
    $expired = Fx::priceList($this->tenant, 'OUTUBRO', PriceListScope::Customer, $company->id, from: '2026-10-01', until: '2026-10-31', activate: false);
    Fx::item($this->tenant, $default, $this->sku, '2,49', [['24', '2,29']]);
    Fx::item($this->tenant, $promo, $this->sku, '2,39');
    Fx::item($this->tenant, $groupList, $this->sku, '2,35');
    Fx::item($this->tenant, $customer, $this->sku, '2,30', [['12', '2,15'], ['48', '1,99']]);
    Fx::item($this->tenant, $expired, $this->sku, '1,50');
    $negotiated = Fx::negotiated($this->tenant, $company, $this->sku, '2,05', '2026-11-01', notes: 'Contrato anual');

    $simulation = ($this->simulate)($this->viewer, $company, $this->sku, '24');

    $order = array_map(static fn (SimulatedCandidate $c): array => [$c->position, $c->source, $c->referenceId, $c->winner], $simulation->candidates);

    expect($order)->toBe([
        [1, PriceSource::Negotiated, $negotiated->id, true],
        [2, PriceSource::CustomerList, $customer->id, false],
        [3, PriceSource::GroupList, $groupList->id, false],
        [4, PriceSource::DefaultList, $promo->id, false],
        [5, PriceSource::DefaultList, $default->id, false],
    ])
        ->and($simulation->resolved?->source)->toBe(PriceSource::Negotiated)
        ->and($simulation->resolved?->unitPrice->cents)->toBe(205)
        ->and($simulation->lineTotal?->cents)->toBe(4920)
        ->and($simulation->winner()?->referenceId)->toBe($negotiated->id);

    [$winner, $customerCandidate, $groupCandidate, $promoCandidate, $defaultCandidate] = $simulation->candidates;

    expect($winner->explanation)->toContain('Maior precedência entre 5 candidatos')
        ->and($winner->description)->toBe('Condição negociada · Contrato anual')
        ->and($customerCandidate->explanation)->toBe('Condição negociada tem precedência sobre Tabela do cliente.')
        ->and($customerCandidate->unitPrice->cents)->toBe(215)
        ->and($customerCandidate->appliedTier?->minQuantity->toDatabase())->toBe('12.000')
        ->and($customerCandidate->description)->toBe('Tabela HIGIENE · Tabela HIGIENE')
        ->and($groupCandidate->explanation)->toBe('Condição negociada tem precedência sobre Tabela do grupo.')
        ->and($promoCandidate->priority)->toBe(5)
        ->and($defaultCandidate->unitPrice->cents)->toBe(229)
        ->and($defaultCandidate->baseUnitPrice->cents)->toBe(249);
});

it('explica os desempates dentro do mesmo nível', function (): void {
    $company = Fx::company($this->tenant, 'Lavanderia Bolha de Sabão');

    $high = Fx::priceList($this->tenant, 'ALTA', priority: 10, from: '2026-01-01');
    $recent = Fx::priceList($this->tenant, 'RECENTE', priority: 0, from: '2026-09-01');
    $older = Fx::priceList($this->tenant, 'ANTIGA', priority: 0, from: '2026-02-01');
    usleep(2_000);
    $twin = Fx::priceList($this->tenant, 'GEMEA', priority: 0, from: '2026-09-01');
    foreach ([$high, $recent, $older, $twin] as $list) {
        Fx::item($this->tenant, $list, $this->sku, '2,49');
    }

    $simulation = ($this->simulate)($this->viewer, $company, $this->sku, '1');
    $explanations = array_map(static fn (SimulatedCandidate $c): array => [$c->referenceId, $c->explanation], $simulation->candidates);

    expect($explanations)->toBe([
        [$high->id, 'Maior precedência entre 4 candidatos (origem, prioridade, início de vigência e criação).'],
        [$recent->id, 'Mesma origem; prioridade 0 menor que 10.'],
        [$twin->id, 'Mesma origem; prioridade 0 menor que 10.'],
        [$older->id, 'Mesma origem; prioridade 0 menor que 10.'],
    ]);

    // Sem a tabela de maior prioridade, os critérios seguintes aparecem.
    $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(ArchivePriceList::class)->handle($high));
    $simulation = ($this->simulate)($this->viewer, $company, $this->sku, '1');

    expect(array_map(static fn (SimulatedCandidate $c): string => $c->explanation, $simulation->candidates))->toBe([
        'Maior precedência entre 3 candidatos (origem, prioridade, início de vigência e criação).',
        'Empate em origem, prioridade e início de vigência; prevalece a tabela cadastrada primeiro.',
        'Mesma origem e prioridade; início de vigência em 01/02/2026 anterior ao da vencedora (01/09/2026).',
    ])
        ->and($simulation->candidates[0]->referenceId)->toBe($recent->id)
        ->and($simulation->candidates[1]->referenceId)->toBe($twin->id);
});

it('informa ausência de preço quando nenhum candidato está vigente na data', function (): void {
    $company = Fx::company($this->tenant, 'Hotel Pousada das Águas');
    Fx::item($this->tenant, Fx::priceList($this->tenant, 'DEZEMBRO', from: '2026-12-01'), $this->sku, '2,49');

    $simulation = ($this->simulate)($this->viewer, $company, $this->sku, '1', '2026-11-30');

    expect($simulation->resolved)->toBeNull()
        ->and($simulation->candidates)->toBe([])
        ->and($simulation->lineTotal)->toBeNull()
        ->and($simulation->toArray()['available'])->toBeFalse();

    $december = ($this->simulate)($this->viewer, $company, $this->sku, '1', '2026-12-01');

    expect($december->resolved?->unitPrice->cents)->toBe(249)
        ->and($december->candidates[0]->explanation)->toBe('Única fonte de preço vigente para a empresa, o SKU e a data.');
});

it('exige pricing.view e quantidade positiva', function (): void {
    $company = Fx::company($this->tenant, 'Hotel Pousada das Águas');
    $warehouse = $this->tenant->addStaff(SystemRole::Warehouse);
    $buyer = $this->tenant->addBuyerUser($company);

    expect(fn () => ($this->simulate)($warehouse, $company, $this->sku, '1'))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->simulate)($buyer, $company, $this->sku, '1'))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->simulate)($this->viewer, $company, $this->sku, '0'))->toThrow(InvalidQuantity::class);
});

it('trata empresa e SKU de outro tenant como inexistentes', function (): void {
    $other = TenantScenario::create();
    $foreignCompany = Fx::company($other, 'Hotel Pousada das Águas');
    $foreignSku = Fx::sku($other, 'DET-500', 'Detergente líquido neutro 500 ml');
    $company = Fx::company($this->tenant, 'Hotel Pousada das Águas');

    expect(fn () => ($this->simulate)($this->viewer, $foreignCompany, $this->sku, '1'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->simulate)($this->viewer, $company, $foreignSku, '1'))->toThrow(ModelNotFoundException::class);
});
