<?php

declare(strict_types=1);

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\Exceptions\InvalidInventoryOperation;
use App\Actions\Inventory\ReceiveStock;
use App\Actions\Inventory\TransferStock;
use App\Data\Inventory\StockBalance;
use App\Data\Inventory\StockTransferResult;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Catalog\Sku;
use App\Models\Identity\Membership;
use App\Models\Inventory\StockMovement;
use App\Models\Inventory\Warehouse;
use App\Services\Inventory\StockLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

/*
| Entradas, ajustes e transferências pela equipe, sempre pelo ledger
| (ADR-005): regras verificadas na Action sob lock e garantidas pelo banco.
*/

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->stockist = $this->tenant->addStaff(SystemRole::Warehouse);
    $this->cd1 = Fx::warehouse($this->tenant, 'CD1', ['priority' => 20]);
    $this->cd2 = Fx::warehouse($this->tenant, 'CD2', ['priority' => 10]);
    $this->sku = Fx::sku($this->tenant, 'PAR-M8', 'UN', 'Parafuso sextavado M8');
    $this->bulk = Fx::sku($this->tenant, 'CIM-GRANEL', 'KG', 'Cimento a granel');

    $this->receive = fn (Warehouse $warehouse, Sku $sku, string $quantity, ?string $note = null, ?Membership $actor = null): StockBalance => $this->tenant->actingAs(
        $actor ?? $this->stockist,
        static fn (): StockBalance => app(ReceiveStock::class)->handle($warehouse, $sku, Quantity::of($quantity), $note),
    );

    $this->adjust = fn (Warehouse $warehouse, Sku $sku, string $delta, string $reason, ?Membership $actor = null): StockBalance => $this->tenant->actingAs(
        $actor ?? $this->stockist,
        static fn (): StockBalance => app(AdjustStock::class)->handle($warehouse, $sku, Quantity::of($delta), $reason),
    );

    $this->transfer = fn (Warehouse $from, Warehouse $to, Sku $sku, string $quantity, ?string $note = null, ?Membership $actor = null): StockTransferResult => $this->tenant->actingAs(
        $actor ?? $this->stockist,
        static fn (): StockTransferResult => app(TransferStock::class)->handle($from, $to, $sku, Quantity::of($quantity), $note),
    );

    $this->movements = fn (): array => $this->tenant->system(static fn (): array => StockMovement::query()
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->map(static fn (StockMovement $m): array => [
            'type' => $m->type->value,
            'warehouse' => $m->warehouse_id,
            'quantity' => $m->quantity->toDatabase(),
            'reference_type' => $m->reference_type,
            'reason' => $m->reason,
            'actor' => $m->actor_user_id,
        ])
        ->all());
});

it('registra entrada na unidade base com responsável e observação', function (): void {
    $balance = ($this->receive)($this->cd1, $this->sku, '120', '  NF 4512   Fornecedor Aço Forte ');
    ($this->receive)($this->cd1, $this->sku, '30');

    expect($balance->onHand->toDatabase())->toBe('120.000')
        ->and($balance->available()->toDatabase())->toBe('120.000')
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '150.000', 'reserved' => '0.000'])
        ->and(($this->movements)())->toBe([
            ['type' => 'receipt', 'warehouse' => $this->cd1->id, 'quantity' => '120.000', 'reference_type' => 'manual', 'reason' => 'NF 4512 Fornecedor Aço Forte', 'actor' => $this->stockist->user_id],
            ['type' => 'receipt', 'warehouse' => $this->cd1->id, 'quantity' => '30.000', 'reference_type' => 'manual', 'reason' => ReceiveStock::DEFAULT_NOTE, 'actor' => $this->stockist->user_id],
        ]);
});

it('respeita as casas decimais da unidade base do produto', function (): void {
    expect(fn () => ($this->receive)($this->cd1, $this->sku, '2.5'))
        ->toThrow(InvalidInventoryOperation::class, 'A unidade base UN não aceita casas decimais.')
        ->and(fn () => ($this->adjust)($this->cd1, $this->sku, '-0.5', 'Contagem cíclica'))
        ->toThrow(InvalidInventoryOperation::class, 'A unidade base UN não aceita casas decimais.');

    ($this->receive)($this->cd1, $this->bulk, '2.5');
    ($this->adjust)($this->cd1, $this->bulk, '-0.125', 'Perda na pesagem');

    expect(Fx::level($this->tenant, $this->cd1, $this->bulk))->toBe(['on_hand' => '2.375', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBeNull();
});

it('recusa entrada sem quantidade, em depósito inativo ou de SKU arquivado', function (): void {
    $inactive = Fx::warehouse($this->tenant, 'CD-ANTIGO', ['is_active' => false]);
    $archived = Fx::sku($this->tenant, 'PAR-M6', 'UN', 'Parafuso sextavado M6', ['status' => SkuStatus::Archived]);

    expect(fn () => ($this->receive)($this->cd1, $this->sku, '0'))->toThrow(InvalidInventoryOperation::class, 'Informe uma quantidade maior que zero.')
        ->and(fn () => ($this->receive)($inactive, $this->sku, '5'))->toThrow(InvalidInventoryOperation::class, 'O depósito CD-ANTIGO está inativo e não recebe entradas.')
        ->and(fn () => ($this->receive)($this->cd1, $archived, '5'))->toThrow(InvalidInventoryOperation::class, 'O SKU PAR-M6 está arquivado e não recebe entradas.')
        ->and(Fx::movementCount($this->tenant))->toBe(0);
});

it('ajusta para mais e para menos com motivo obrigatório e auditoria', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->sku, '50');

    ($this->adjust)($this->cd1, $this->sku, '4', 'Contagem encontrou caixa extra');
    $balance = ($this->adjust)($this->cd1, $this->sku, '-10', 'Avaria no transporte');

    $logs = $this->tenant->system(static fn (): array => AuditLog::query()->where('action', 'stock.adjusted')->orderBy('created_at')->orderBy('id')->get()->all());

    expect($balance->onHand->toDatabase())->toBe('44.000')
        ->and(Fx::movementCount($this->tenant, 'adjustment_in'))->toBe(1)
        ->and(Fx::movementCount($this->tenant, 'adjustment_out'))->toBe(1)
        ->and(array_column(($this->movements)(), 'reference_type'))->toBe(['manual', 'adjustment', 'adjustment'])
        ->and($logs)->toHaveCount(2)
        ->and($logs[1]->subject_id)->toBe($this->cd1->id)
        ->and($logs[1]->reason)->toBe('Avaria no transporte')
        ->and($logs[1]->actor_membership_id)->toBe($this->stockist->id)
        ->and($logs[1]->data)->toMatchArray(['sku_code' => 'PAR-M8', 'delta' => '-10.000', 'on_hand_before' => '54.000', 'on_hand_after' => '44.000', 'unit' => 'UN']);
});

it('recusa ajuste sem motivo ou sem quantidade', function (string $delta, string $reason, string $field): void {
    Fx::receive($this->tenant, $this->cd1, $this->sku, '10');

    try {
        ($this->adjust)($this->cd1, $this->sku, $delta, $reason);
        $this->fail('Ajuste inválido aceito.');
    } catch (InvalidInventoryOperation $e) {
        expect($e->fieldName())->toBe($field);
    }

    expect(Fx::movementCount($this->tenant))->toBe(1);
})->with([
    'motivo vazio' => ['-1', '   ', 'reason'],
    'motivo curto' => ['-1', 'erro', 'reason'],
    'motivo longo' => ['-1', str_repeat('a', 501), 'reason'],
    'quantidade zero' => ['0', 'Contagem cíclica', 'quantity'],
]);

it('transfere entre depósitos com saída e entrada no mesmo transfer_id', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->sku, '40');

    $result = ($this->transfer)($this->cd1, $this->cd2, $this->sku, '15', 'Reposição da loja');

    $legs = $this->tenant->system(static fn (): array => StockMovement::query()->where('transfer_id', $result->transferId)->orderBy('type')->get()->all());

    expect($result->from->onHand->toDatabase())->toBe('25.000')
        ->and($result->to->onHand->toDatabase())->toBe('15.000')
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '25.000', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->sku))->toBe(['on_hand' => '15.000', 'reserved' => '0.000'])
        ->and(array_map(static fn (StockMovement $m): array => [$m->type->value, $m->warehouse_id, $m->quantity->toDatabase(), $m->reference_type, $m->reference_id, $m->reason, $m->actor_user_id], $legs))
        ->toBe([
            ['transfer_in', $this->cd2->id, '15.000', 'transfer', $result->transferId, 'Reposição da loja', $this->stockist->user_id],
            ['transfer_out', $this->cd1->id, '15.000', 'transfer', $result->transferId, 'Reposição da loja', $this->stockist->user_id],
        ]);
});

it('exige destino ativo e diferente da origem e saldo suficiente na origem', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->sku, '10');
    $inactive = Fx::warehouse($this->tenant, 'CD-ANTIGO', ['is_active' => false]);

    expect(fn () => ($this->transfer)($this->cd1, $this->cd1, $this->sku, '1'))
        ->toThrow(InvalidInventoryOperation::class, 'Escolha um depósito de destino diferente da origem.')
        ->and(fn () => ($this->transfer)($this->cd1, $inactive, $this->sku, '1'))
        ->toThrow(InvalidInventoryOperation::class, 'O depósito CD-ANTIGO está inativo e não recebe transferências.')
        ->and(fn () => ($this->transfer)($this->cd1, $this->cd2, $this->sku, '11'))
        ->toThrow(InsufficientStock::class, 'A transferência de 11 UN excede o disponível do SKU PAR-M8 no depósito CD1 (físico 10, reservado 0, disponível 10).')
        ->and(fn () => ($this->transfer)($this->cd2, $this->cd1, $this->sku, '1'))
        ->toThrow(InsufficientStock::class)
        ->and(fn () => ($this->transfer)($this->cd1, $this->cd2, $this->sku, '1.5'))
        ->toThrow(InvalidInventoryOperation::class, 'A unidade base UN não aceita casas decimais.')
        ->and(Fx::movementCount($this->tenant))->toBe(1);
});

it('recusa ajuste de saída sem saldo no depósito', function (): void {
    expect(fn () => ($this->adjust)($this->cd2, $this->sku, '-1', 'Perda identificada'))
        ->toThrow(InsufficientStock::class, 'O ajuste de saída de 1 UN excede o disponível do SKU PAR-M8 no depósito CD2 (físico 0, reservado 0, disponível 0).')
        ->and(Fx::level($this->tenant, $this->cd2, $this->sku))->toBeNull();
});

it('mantém cada saldo igual à soma dos seus movimentos após entradas, ajustes e transferências', function (): void {
    ($this->receive)($this->cd1, $this->sku, '100');
    ($this->receive)($this->cd2, $this->bulk, '12.5');
    ($this->adjust)($this->cd1, $this->sku, '-20', 'Avaria no transporte');
    ($this->transfer)($this->cd1, $this->cd2, $this->sku, '25');
    ($this->adjust)($this->cd2, $this->bulk, '0.75', 'Recontagem do lote');
    ($this->transfer)($this->cd2, $this->cd1, $this->sku, '5');

    expect(Fx::divergences($this->tenant))->toBe([])
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '60.000', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->sku))->toBe(['on_hand' => '20.000', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->bulk))->toBe(['on_hand' => '13.250', 'reserved' => '0.000']);
});

it('permite esvaziar depósito inativo por transferência para um ativo', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->sku, '8');
    $this->tenant->system(fn () => Warehouse::query()->whereKey($this->cd1->id)->update(['is_active' => false]));

    ($this->transfer)($this->cd1, $this->cd2, $this->sku, '8');

    expect(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '0.000', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->sku))->toBe(['on_hand' => '8.000', 'reserved' => '0.000']);
});

it('desfaz as duas pontas da transferência quando uma delas é recusada pelo banco (RN-074)', function (): void {
    Fx::receive($this->tenant, $this->cd1, $this->sku, '3');
    Fx::receive($this->tenant, $this->cd2, $this->sku, '1');

    // Sem a verificação da Action: a saída da origem viola o CHECK depois
    // (ou antes) da entrada no destino; nenhuma das pontas pode permanecer.
    expect(fn () => $this->tenant->actingAs($this->stockist, fn () => app(StockLedger::class)->transfer($this->cd1->id, $this->cd2->id, $this->sku->id, Quantity::of('5'), 'Direto')))
        ->toThrow(QueryException::class, 'stock_levels_')
        ->and(fn () => $this->tenant->actingAs($this->stockist, fn () => app(StockLedger::class)->transfer($this->cd2->id, $this->cd1->id, $this->sku->id, Quantity::of('5'), 'Direto')))
        ->toThrow(QueryException::class, 'stock_levels_')
        ->and(Fx::movementCount($this->tenant, 'transfer_in'))->toBe(0)
        ->and(Fx::movementCount($this->tenant, 'transfer_out'))->toBe(0)
        ->and(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '3.000', 'reserved' => '0.000'])
        ->and(Fx::level($this->tenant, $this->cd2, $this->sku))->toBe(['on_hand' => '1.000', 'reserved' => '0.000']);
});

it('exige inventory.manage para movimentar e não grava nada sem permissão', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    Fx::receive($this->tenant, $this->cd1, $this->sku, '10');

    expect(fn () => ($this->receive)($this->cd1, $this->sku, '1', null, $actor))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->adjust)($this->cd1, $this->sku, '-1', 'Perda identificada', $actor))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->transfer)($this->cd1, $this->cd2, $this->sku, '1', null, $actor))->toThrow(AuthorizationException::class)
        ->and(Fx::movementCount($this->tenant))->toBe(1);
})->with([
    'consulta' => [SystemRole::Viewer],
    'representante comercial' => [SystemRole::SalesRep],
    'gerente comercial' => [SystemRole::SalesManager],
    'financeiro' => [SystemRole::Finance],
]);

it('permite movimentar ao administrador e ao proprietário', function (): void {
    ($this->receive)($this->cd1, $this->sku, '2', null, $this->tenant->addStaff(SystemRole::Admin));
    ($this->receive)($this->cd1, $this->sku, '3', null, $this->tenant->ownerMembership);

    expect(Fx::level($this->tenant, $this->cd1, $this->sku))->toBe(['on_hand' => '5.000', 'reserved' => '0.000']);
});

it('nega movimentações ao comprador, na Action e no banco', function (): void {
    $buyer = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin);

    expect(fn () => ($this->receive)($this->cd1, $this->sku, '1', null, $buyer))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->adjust)($this->cd1, $this->sku, '1', 'Contagem cíclica', $buyer))->toThrow(AuthorizationException::class);

    // Mesmo chamando o ledger diretamente, o contexto de comprador não grava movimentos.
    expect(fn () => $this->tenant->actingAs($buyer, fn () => app(StockLedger::class)->receive($this->cd1->id, $this->sku->id, Quantity::of('1'), 'Indevido')))
        ->toThrow(QueryException::class, 'row-level security')
        ->and(Fx::movementCount($this->tenant))->toBe(0);
});

it('isola os tenants: depósito e SKU de outro distribuidor equivalem a inexistentes', function (): void {
    $other = TenantScenario::create();
    $foreignWarehouse = Fx::warehouse($other, 'CD1');
    $foreignSku = Fx::sku($other, 'PAR-M8');
    Fx::receive($other, $foreignWarehouse, $foreignSku, '9');

    expect(fn () => ($this->receive)($foreignWarehouse, $this->sku, '1'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->receive)($this->cd1, $foreignSku, '1'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->adjust)($foreignWarehouse, $this->sku, '-1', 'Tentativa indevida'))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->transfer)($this->cd1, $foreignWarehouse, $this->sku, '1'))->toThrow(ModelNotFoundException::class)
        ->and(Fx::movementCount($this->tenant))->toBe(0)
        ->and(Fx::movementCount($other))->toBe(1)
        ->and(Fx::level($other, $foreignWarehouse, $foreignSku))->toBe(['on_hand' => '9.000', 'reserved' => '0.000']);

    // Saldos de outro tenant não aparecem nem por consulta direta.
    expect($this->tenant->actingAs($this->stockist, static fn (): int => DB::table('stock_levels')->count()))->toBe(0);
});
