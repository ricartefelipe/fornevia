<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Inventory\StockOperations;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Tela de movimentação: entradas, ajustes e transferências digitados em
| pt-BR, na unidade base do produto, com as regras exibidas nos campos.
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->stockist = $this->tenant->addStaff(SystemRole::Warehouse);
    $this->cd1 = Fx::warehouse($this->tenant, 'CD1', ['priority' => 20]);
    $this->cd2 = Fx::warehouse($this->tenant, 'CD2', ['priority' => 10]);
    $this->bolt = Fx::sku($this->tenant, 'PAR-M8', 'UN', 'Parafuso sextavado M8');
    $this->cement = Fx::sku($this->tenant, 'CIM-GRANEL', 'KG', 'Cimento a granel');
});

it('registra entrada buscando o SKU e interpretando "1.000" como mil', function (): void {
    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(StockOperations::class)
        ->assertSet('operation', 'receipt')
        ->set('skuSearch', 'parafuso')
        ->assertSee('PAR-M8')
        ->call('selectSku', $this->bolt->id)
        ->assertSet('skuId', $this->bolt->id)
        ->assertSee('Na unidade base UN, sem casas decimais.')
        ->set('warehouseId', $this->cd1->id)
        ->set('quantity', '1.000')
        ->set('reason', 'NF 4512')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('message', 'Entrada de 1.000 UN do SKU PAR-M8 registrada no depósito CD1. Físico: 1.000 UN; disponível: 1.000 UN.')
        ->assertSet('quantity', '')
        ->assertSet('reason', '')
        ->assertSeeInOrder(['Saldo do SKU', 'CD1', '1.000', '0', '1.000']));

    expect(Fx::level($this->tenant, $this->cd1, $this->bolt))->toBe(['on_hand' => '1000.000', 'reserved' => '0.000']);
});

it('aceita decimais conforme a unidade base e recusa quantidades inválidas no campo', function (): void {
    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(StockOperations::class)
        ->call('selectSku', $this->bolt->id)
        ->set('warehouseId', $this->cd1->id)
        ->set('quantity', '2,5')
        ->call('submit')
        ->assertHasErrors(['quantity' => 'A unidade base UN não aceita casas decimais.'])
        ->set('quantity', 'dez')
        ->call('submit')
        ->assertHasErrors(['quantity' => 'Informe uma quantidade com até 3 casas decimais, por exemplo 1.000 ou 2,5.'])
        ->set('quantity', '1000000000000000')
        ->call('submit')
        ->assertHasErrors(['quantity' => 'Quantidade acima do limite suportado.'])
        ->call('clearSku')
        ->call('selectSku', $this->cement->id)
        ->set('quantity', '2,5')
        ->call('submit')
        ->assertHasNoErrors());

    expect(Fx::level($this->tenant, $this->cd1, $this->cement))->toBe(['on_hand' => '2.500', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd1, $this->bolt))->toBeNull();
});

it('exige SKU, depósito e motivo do ajuste', function (): void {
    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(StockOperations::class)
        ->set('operation', 'adjustment')
        ->set('quantity', '1')
        ->call('submit')
        ->assertHasErrors(['warehouseId', 'reason'])
        ->set('warehouseId', $this->cd1->id)
        ->set('reason', 'Contagem cíclica')
        ->call('submit')
        ->assertHasErrors(['skuSearch' => 'Selecione um SKU pela busca.'])
        ->call('selectSku', $this->bolt->id)
        ->set('reason', 'erro')
        ->call('submit')
        ->assertHasErrors(['reason' => 'Informe o motivo (ao menos 5 caracteres).']));

    expect(Fx::movementCount($this->tenant))->toBe(0);
});

it('mostra no campo a recusa de ajuste de saída acima do disponível e registra o ajuste válido', function (): void {
    $scenario = OrderingScenario::create(stock: '10');
    Fx::confirmOrder($scenario, '7');
    $stockist = $scenario->tenant->addStaff(SystemRole::Warehouse);
    $code = $scenario->sku->code;

    Fx::asMember($scenario->tenant, $stockist, fn () => Livewire::test(StockOperations::class)
        ->set('operation', 'adjustment')
        ->call('selectSku', $scenario->sku->id)
        ->assertSeeInOrder(['Saldo do SKU', 'CD1', '10', '7', '3'])
        ->set('warehouseId', $scenario->warehouse->id)
        ->set('direction', 'out')
        ->set('quantity', '4')
        ->set('reason', 'Avaria no transporte')
        ->call('submit')
        ->assertHasErrors(['quantity' => "O ajuste de saída de 4 UN excede o disponível do SKU {$code} no depósito CD1 (físico 10, reservado 7, disponível 3). Informe uma quantidade menor ou libere reservas antes."])
        ->assertSet('reason', 'Avaria no transporte')
        ->set('quantity', '3')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('message', "Ajuste de saída de 3 UN do SKU {$code} registrado no depósito CD1. Físico: 7 UN; disponível: 0 UN.")
        ->set('direction', 'in')
        ->set('quantity', '2')
        ->set('reason', 'Recontagem do lote')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('message', "Ajuste de entrada de 2 UN do SKU {$code} registrado no depósito CD1. Físico: 9 UN; disponível: 2 UN."));

    expect(Fx::level($scenario->tenant, $scenario->warehouse, $scenario->sku))->toBe(['on_hand' => '9.000', 'reserved' => '7.000']);
});

it('transfere entre depósitos pela tela e mostra os erros de destino', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->bolt, '20');

    Fx::asMember($this->tenant, $this->stockist, fn () => Livewire::test(StockOperations::class)
        ->set('operation', 'transfer')
        ->call('selectSku', $this->bolt->id)
        ->set('warehouseId', $this->cd1->id)
        ->set('quantity', '8')
        ->call('submit')
        ->assertHasErrors(['toWarehouseId'])
        ->set('toWarehouseId', $this->cd1->id)
        ->call('submit')
        ->assertHasErrors(['toWarehouseId' => 'Escolha um depósito de destino diferente da origem.'])
        ->set('toWarehouseId', $this->cd2->id)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('message', 'Transferência de 8 UN do SKU PAR-M8 registrada: CD1 → CD2. Disponível na origem: 12 UN; no destino: 8 UN.'));

    expect(Fx::level($this->tenant, $this->cd1, $this->bolt))->toBe(['on_hand' => '12.000', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->bolt))->toBe(['on_hand' => '8.000', 'reserved' => '0.000']);
});

it('oferece apenas depósitos ativos para entradas e destinos de transferência', function (): void {
    $inactive = Fx::warehouse($this->tenant, 'CD-ANTIGO', ['is_active' => false]);

    Fx::asMember($this->tenant, $this->stockist, static fn () => Livewire::test(StockOperations::class)
        ->assertViewHas('originOptions', static fn (array $options): bool => ! array_key_exists($inactive->id, $options))
        ->set('operation', 'adjustment')
        ->assertViewHas('originOptions', static fn (array $options): bool => array_key_exists($inactive->id, $options))
        ->set('operation', 'transfer')
        ->assertViewHas('originOptions', static fn (array $options): bool => array_key_exists($inactive->id, $options))
        ->assertViewHas('destinationOptions', static fn (array $options): bool => ! array_key_exists($inactive->id, $options))
        ->set('operation', 'desconhecida')
        ->assertSet('operation', 'receipt'));
});

it('preenche SKU e depósito a partir da lista de saldos, ignorando identificadores inválidos', function (): void {
    $other = TenantScenario::create();
    $foreignSku = Fx::sku($other, 'SKU-ALHEIO');

    Fx::asMember($this->tenant, $this->stockist, function () use ($foreignSku): void {
        Livewire::withQueryParams(['sku' => $this->bolt->id, 'deposito' => $this->cd2->id, 'operacao' => 'adjustment'])
            ->test(StockOperations::class)
            ->assertSet('skuId', $this->bolt->id)
            ->assertSet('warehouseId', $this->cd2->id)
            ->assertSet('operation', 'adjustment');

        Livewire::withQueryParams(['sku' => $foreignSku->id, 'deposito' => 'CD1'])
            ->test(StockOperations::class)
            ->assertSet('skuId', null)
            ->assertSet('warehouseId', '');
    });
});

it('protege o SKU selecionado e trata SKU ou depósito de outro tenant como inexistentes', function (): void {
    $other = TenantScenario::create();
    $foreignSku = Fx::sku($other, 'SKU-ALHEIO');
    $foreignWarehouse = Fx::warehouse($other, 'CD9');

    Fx::asMember($this->tenant, $this->stockist, function () use ($foreignSku, $foreignWarehouse): void {
        $component = Livewire::test(StockOperations::class)->call('selectSku', $this->bolt->id);

        expect(fn () => $component->set('skuId', $foreignSku->id))->toThrow(CannotUpdateLockedPropertyException::class);

        Livewire::test(StockOperations::class)->call('selectSku', $foreignSku->id)->assertNotFound();
        Livewire::test(StockOperations::class)->call('selectSku', 'PAR-M8')->assertNotFound();

        Livewire::test(StockOperations::class)
            ->call('selectSku', $this->bolt->id)
            ->set('warehouseId', $foreignWarehouse->id)
            ->set('quantity', '1')
            ->call('submit')
            ->assertNotFound();
    });

    expect(Fx::movementCount($this->tenant))->toBe(0)
        ->and(Fx::movementCount($other))->toBe(0);
});

it('recusa no servidor a movimentação de quem só consulta', function (): void {
    Fx::asMember($this->tenant, $this->tenant->addStaff(SystemRole::Viewer), fn () => Livewire::test(StockOperations::class)
        ->call('selectSku', $this->bolt->id)
        ->set('warehouseId', $this->cd1->id)
        ->set('quantity', '5')
        ->call('submit')
        ->assertForbidden());

    expect(Fx::movementCount($this->tenant))->toBe(0);
});
