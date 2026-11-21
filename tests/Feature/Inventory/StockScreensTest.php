<?php

declare(strict_types=1);

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\TransferStock;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Inventory\StockLevelsIndex;
use App\Livewire\Staff\Inventory\StockMovementsIndex;
use App\Models\Identity\User;
use App\Models\Inventory\StockLevel;
use App\Models\Inventory\StockMovement;
use App\Services\Inventory\StockLedger;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Livewire;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Consulta de saldos e do histórico de movimentações pela equipe.
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->viewer = $this->tenant->addStaff(SystemRole::Viewer);
    $this->cd1 = Fx::warehouse($this->tenant, 'CD1', ['priority' => 20]);
    $this->cd2 = Fx::warehouse($this->tenant, 'LOJA-SP', ['priority' => 5, 'name' => 'Loja São Paulo']);
    $this->bolt = Fx::sku($this->tenant, 'PAR-M8', 'UN', 'Parafuso sextavado M8');
    $this->cement = Fx::sku($this->tenant, 'CIM-GRANEL', 'KG', 'Cimento a granel');
});

it('mostra físico, reservado e disponível de cada SKU por depósito', function (): void {
    $scenario = OrderingScenario::create(stock: '10');
    Fx::confirmOrder($scenario, '7');
    $code = $scenario->sku->code;

    Fx::asMember($scenario->tenant, $scenario->tenant->addStaff(SystemRole::Viewer), static fn () => Livewire::test(StockLevelsIndex::class)
        ->assertSeeInOrder([$code, 'CD1', '10 UN', '7 UN', '3 UN'])
        ->assertDontSee('Movimentar estoque'));
});

it('busca por código ou nome, filtra por depósito e ignora filtros malformados', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->bolt, '1250');
    Fx::receive($this->tenant, $this->cd2, $this->bolt, '40');
    Fx::receive($this->tenant, $this->cd1, $this->cement, '12.5');

    $codes = static fn (LengthAwarePaginator $page): array => $page->getCollection()
        ->map(static fn (StockLevel $level): string => $level->sku->code.'@'.$level->warehouse->code)
        ->all();

    Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(StockLevelsIndex::class)
        ->assertViewHas('levels', static fn (LengthAwarePaginator $p): bool => $codes($p) === ['CIM-GRANEL@CD1', 'PAR-M8@CD1', 'PAR-M8@LOJA-SP'])
        ->assertSee('1.250 UN')
        ->assertSee('12,5 KG')
        ->set('search', 'cimento')
        ->assertViewHas('levels', static fn (LengthAwarePaginator $p): bool => $codes($p) === ['CIM-GRANEL@CD1'])
        ->set('search', 'par-m')
        ->set('warehouseId', $this->cd2->id)
        ->assertViewHas('levels', static fn (LengthAwarePaginator $p): bool => $codes($p) === ['PAR-M8@LOJA-SP'])
        ->set('search', '%')
        ->assertViewHas('levels', static fn (LengthAwarePaginator $p): bool => $codes($p) === [])
        ->set('search', '')
        ->set('warehouseId', 'nao-e-uuid')
        ->assertViewHas('levels', static fn (LengthAwarePaginator $p): bool => $p->total() === 3)
        ->call('clearFilters')
        ->assertSet('warehouseId', ''));
});

it('filtra somente saldos com estoque e pagina de 25 em 25', function (): void {
    $skus = array_map(fn (int $i) => Fx::sku($this->tenant, sprintf('SKU-%02d', $i), 'UN', "Produto {$i}"), range(1, 26));

    foreach ($skus as $sku) {
        Fx::receive($this->tenant, $this->cd1, $sku, '1');
    }

    $this->tenant->system(fn () => app(StockLedger::class)->adjust($this->cd1->id, $skus[0]->id, Quantity::of('-1'), 'Baixa total'));

    Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(StockLevelsIndex::class)
        ->assertViewHas('levels', static fn (LengthAwarePaginator $p): bool => $p->count() === 25 && $p->total() === 26)
        ->set('onlyWithStock', true)
        ->assertViewHas('levels', static fn (LengthAwarePaginator $p): bool => $p->total() === 25));
});

it('mostra o histórico com referência, motivo e responsável', function (): void {
    $scenario = OrderingScenario::create(stock: '10');
    $order = Fx::confirmOrder($scenario, '4');
    $stockistUser = User::factory()->create(['name' => 'Joana Almoxarife']);
    $stockist = $scenario->tenant->addStaff(SystemRole::Warehouse, $stockistUser);
    $cd2 = Fx::warehouse($scenario->tenant, 'CD2', ['priority' => 1]);

    $scenario->tenant->actingAs($stockist, static function () use ($scenario, $cd2): void {
        app(AdjustStock::class)->handle($scenario->warehouse, $scenario->sku, Quantity::of('-2'), 'Avaria no transporte');
        app(TransferStock::class)->handle($scenario->warehouse, $cd2, $scenario->sku, Quantity::of('3'), 'Reposição da filial');
    });

    Fx::asMember($scenario->tenant, $scenario->tenant->addStaff(SystemRole::Viewer), static fn () => Livewire::test(StockMovementsIndex::class)
        ->assertSee("Pedido {$order['number']}")
        ->assertSee('Reserva')
        ->assertSee('Ajuste de saída')
        ->assertSee('Avaria no transporte')
        ->assertSee('Transferência para CD2')
        ->assertSee('Transferência de CD1')
        ->assertSee('Reposição da filial')
        ->assertSee('Joana Almoxarife')
        ->assertSee('Entrada manual')
        ->assertSee('-2 UN')
        ->assertSee('+3 UN'));
});

it('filtra o histórico por SKU, depósito, tipo e período no fuso comercial', function (): void {
    // 18/11 às 01:30 em UTC ainda é 17/11 em São Paulo.
    $this->travelTo(CarbonImmutable::parse('2026-11-18T01:30:00Z'));
    Fx::receive($this->tenant, $this->cd1, $this->bolt, '100');
    $this->travelTo(CarbonImmutable::parse('2026-11-18T15:00:00Z'));
    Fx::receive($this->tenant, $this->cd2, $this->bolt, '10');
    Fx::receive($this->tenant, $this->cd1, $this->cement, '5');
    $this->travelTo(CarbonImmutable::parse('2026-11-19T12:00:00Z'));
    $this->tenant->system(fn () => app(StockLedger::class)->adjust($this->cd1->id, $this->bolt->id, Quantity::of('-1'), 'Contagem cíclica'));

    $rows = static fn (LengthAwarePaginator $page): array => $page->getCollection()
        ->map(static fn (StockMovement $m): string => $m->type->value.':'.$m->sku->code.'@'.$m->warehouse->code)
        ->all();

    Fx::asMember($this->tenant, $this->viewer, fn () => Livewire::test(StockMovementsIndex::class)
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $p->total() === 4)
        ->set('search', 'PAR-M8')
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $rows($p) === ['adjustment_out:PAR-M8@CD1', 'receipt:PAR-M8@LOJA-SP', 'receipt:PAR-M8@CD1'])
        ->set('warehouseId', $this->cd1->id)
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $rows($p) === ['adjustment_out:PAR-M8@CD1', 'receipt:PAR-M8@CD1'])
        ->set('type', 'receipt')
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $rows($p) === ['receipt:PAR-M8@CD1'])
        ->call('clearFilters')
        ->set('from', '2026-11-18')
        ->set('until', '2026-11-18')
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $rows($p) === ['receipt:CIM-GRANEL@CD1', 'receipt:PAR-M8@LOJA-SP'])
        ->set('from', '')
        ->set('until', '2026-11-17')
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $rows($p) === ['receipt:PAR-M8@CD1'])
        ->set('until', '0000-01-01')
        ->assertSee('Data do período inválida')
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $p->total() === 4)
        ->set('until', '')
        ->set('type', 'desconhecido')
        ->set('warehouseId', 'CD1')
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $p->total() === 4));
});

it('não lista movimentações de outro distribuidor', function (): void {
    $other = TenantScenario::create();
    $foreign = Fx::warehouse($other, 'CD-OUTRO');
    Fx::receive($other, $foreign, Fx::sku($other, 'SKU-ALHEIO'), '3');
    Fx::receive($this->tenant, $this->cd1, $this->bolt, '1');

    Fx::asMember($this->tenant, $this->viewer, static fn () => Livewire::test(StockMovementsIndex::class)
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $p->total() === 1)
        ->assertDontSee('SKU-ALHEIO')
        ->assertDontSee('CD-OUTRO')
        ->set('warehouseId', $foreign->id)
        ->assertViewHas('movements', static fn (LengthAwarePaginator $p): bool => $p->total() === 0));
});
