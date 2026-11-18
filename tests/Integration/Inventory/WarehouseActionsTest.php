<?php

declare(strict_types=1);

use App\Actions\Inventory\DeleteWarehouse;
use App\Actions\Inventory\Exceptions\InvalidInventoryOperation;
use App\Actions\Inventory\Exceptions\WarehouseCodeTaken;
use App\Actions\Inventory\Exceptions\WarehouseHasHistory;
use App\Actions\Inventory\SaveWarehouse;
use App\Data\Inventory\WarehouseData;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Inventory\Warehouse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Inventory\InventoryFixtures as Fx;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->stockist = $this->tenant->addStaff(SystemRole::Warehouse);
    $this->headquarters = $this->tenant->system(static fn (): string => (string) Branch::query()->where('is_headquarters', true)->value('id'));

    $this->data = fn (array $overrides = []): WarehouseData => new WarehouseData(...[
        'code' => 'cd-sul',
        'name' => '  Centro de distribuição   Sul ',
        'branchId' => $this->headquarters,
        'priority' => 20,
        'allowsSales' => true,
        'isActive' => true,
        ...$overrides,
    ]);

    $this->save = fn (Membership $actor, ?Warehouse $warehouse, WarehouseData $data, ?TenantScenario $tenant = null): Warehouse => ($tenant ?? $this->tenant)->actingAs(
        $actor,
        static fn (): Warehouse => app(SaveWarehouse::class)->handle($warehouse, $data),
    );

    $this->stored = fn (?TenantScenario $tenant = null): array => ($tenant ?? $this->tenant)->system(static fn (): array => Warehouse::query()
        ->orderBy('code')
        ->get()
        ->map(static fn (Warehouse $w): array => [$w->code, $w->name, $w->priority, $w->allows_sales, $w->is_active])
        ->all());

    $this->audits = fn (string $prefix): array => $this->tenant->system(static fn (): array => AuditLog::query()
        ->where('action', 'like', $prefix.'%')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all());
});

it('cadastra depósito com código normalizado, nome sem espaços repetidos e auditoria', function (): void {
    $warehouse = ($this->save)($this->stockist, null, ($this->data)());

    expect(($this->stored)())->toBe([['CD-SUL', 'Centro de distribuição Sul', 20, true, true]])
        ->and($warehouse->branch_id)->toBe($this->headquarters);

    $logs = ($this->audits)('warehouse.');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->action)->toBe('warehouse.created')
        ->and($logs[0]->subject_id)->toBe($warehouse->id)
        ->and($logs[0]->actor_membership_id)->toBe($this->stockist->id)
        ->and($logs[0]->data['after']['code'])->toBe('CD-SUL');
});

it('altera prioridade, atendimento de vendas e situação registrando antes e depois', function (): void {
    $warehouse = ($this->save)($this->stockist, null, ($this->data)());

    ($this->save)($this->stockist, $warehouse, ($this->data)(['priority' => 5, 'allowsSales' => false, 'isActive' => false]));
    // Sem alteração: nenhuma auditoria adicional.
    ($this->save)($this->stockist, $warehouse, ($this->data)(['priority' => 5, 'allowsSales' => false, 'isActive' => false]));

    $logs = ($this->audits)('warehouse.');

    expect(($this->stored)())->toBe([['CD-SUL', 'Centro de distribuição Sul', 5, false, false]])
        ->and(array_map(static fn (AuditLog $log): string => $log->action, $logs))->toBe(['warehouse.created', 'warehouse.updated'])
        ->and($logs[1]->data['before'])->toMatchArray(['priority' => 20, 'allows_sales' => true, 'is_active' => true])
        ->and($logs[1]->data['after'])->toMatchArray(['priority' => 5, 'allows_sales' => false, 'is_active' => false]);
});

it('recusa código repetido no tenant, mas aceita o mesmo código em outro distribuidor', function (): void {
    ($this->save)($this->stockist, null, ($this->data)());

    expect(fn () => ($this->save)($this->stockist, null, ($this->data)(['code' => 'CD-SUL ', 'name' => 'Outro'])))
        ->toThrow(WarehouseCodeTaken::class, 'Já existe um depósito com o código CD-SUL.');

    $other = TenantScenario::create();
    $otherBranch = $other->system(static fn (): string => (string) Branch::query()->where('is_headquarters', true)->value('id'));
    ($this->save)($other->addStaff(SystemRole::Warehouse), null, ($this->data)(['branchId' => $otherBranch]), $other);

    expect(($this->stored)())->toHaveCount(1)
        ->and(($this->stored)($other))->toBe([['CD-SUL', 'Centro de distribuição Sul', 20, true, true]]);
});

it('valida código, nome, prioridade e filial', function (array $overrides, string $field): void {
    try {
        ($this->save)($this->stockist, null, ($this->data)($overrides));
        $this->fail('Cadastro inválido aceito.');
    } catch (InvalidInventoryOperation $e) {
        expect($e->fieldName())->toBe($field);
    }

    expect(($this->stored)())->toBe([]);
})->with([
    'código com espaço' => [['code' => 'CD 1'], 'code'],
    'código longo' => [['code' => str_repeat('A', 21)], 'code'],
    'nome vazio' => [['name' => '   '], 'name'],
    'prioridade negativa' => [['priority' => -1], 'priority'],
    'prioridade acima do limite' => [['priority' => 10_000], 'priority'],
    'filial malformada' => [['branchId' => 'matriz'], 'branch_id'],
    'filial inexistente' => [['branchId' => '0199a8b2-0000-7000-8000-000000000000'], 'branch_id'],
]);

it('trata filial de outro tenant como inexistente', function (): void {
    $other = TenantScenario::create();
    $foreignBranch = $other->system(static fn (): string => (string) Branch::query()->where('is_headquarters', true)->value('id'));

    expect(fn () => ($this->save)($this->stockist, null, ($this->data)(['branchId' => $foreignBranch])))
        ->toThrow(InvalidInventoryOperation::class, 'Selecione uma filial ativa.');
});

it('exige inventory.manage para cadastrar, alterar e excluir', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $warehouse = Fx::warehouse($this->tenant, 'CD1');

    expect(fn () => ($this->save)($actor, null, ($this->data)()))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($actor, $warehouse, ($this->data)(['code' => 'CD1'])))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tenant->actingAs($actor, static fn () => app(DeleteWarehouse::class)->handle($warehouse)))->toThrow(AuthorizationException::class)
        ->and(($this->stored)())->toBe([['CD1', 'Depósito CD1', 10, true, true]]);
})->with([
    'consulta' => [SystemRole::Viewer],
    'gerente comercial' => [SystemRole::SalesManager],
    'financeiro' => [SystemRole::Finance],
]);

it('nega o cadastro a usuários de empresa compradora', function (): void {
    $buyer = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin);

    expect(fn () => ($this->save)($buyer, null, ($this->data)()))->toThrow(AuthorizationException::class)
        ->and(($this->stored)())->toBe([]);
});

it('exclui depósito sem histórico e registra a exclusão', function (): void {
    $warehouse = Fx::warehouse($this->tenant, 'ERRADO');

    $this->tenant->actingAs($this->stockist, static fn () => app(DeleteWarehouse::class)->handle($warehouse));

    $logs = ($this->audits)('warehouse.');

    expect(($this->stored)())->toBe([])
        ->and($logs)->toHaveCount(1)
        ->and($logs[0]->action)->toBe('warehouse.deleted')
        ->and($logs[0]->data['before']['code'])->toBe('ERRADO');
});

it('recusa excluir depósito com movimentações, mesmo com saldo zerado', function (): void {
    $warehouse = Fx::warehouse($this->tenant, 'CD1');
    $sku = Fx::sku($this->tenant);
    Fx::receive($this->tenant, $warehouse, $sku, '3');
    $this->tenant->system(static fn () => app(App\Services\Inventory\StockLedger::class)->adjust($warehouse->id, $sku->id, App\Domain\Shared\Quantity::of('-3'), 'Baixa total'));

    expect(fn () => $this->tenant->actingAs($this->stockist, static fn () => app(DeleteWarehouse::class)->handle($warehouse)))
        ->toThrow(WarehouseHasHistory::class, 'O depósito CD1 possui histórico de estoque e não pode ser excluído.')
        ->and(($this->stored)())->toHaveCount(1)
        ->and(Fx::movementCount($this->tenant))->toBe(2);
});

it('não encontra depósito de outro tenant ao alterar ou excluir', function (): void {
    $other = TenantScenario::create();
    $foreign = Fx::warehouse($other, 'CD-OUTRO');

    expect(fn () => ($this->save)($this->stockist, $foreign, ($this->data)()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => $this->tenant->actingAs($this->stockist, static fn () => app(DeleteWarehouse::class)->handle($foreign)))->toThrow(ModelNotFoundException::class)
        ->and(($this->stored)($other))->toBe([['CD-OUTRO', 'Depósito CD-OUTRO', 10, true, true]]);
});
