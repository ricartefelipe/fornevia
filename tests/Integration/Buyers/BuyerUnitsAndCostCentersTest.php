<?php

declare(strict_types=1);

use App\Actions\Buyers\SaveBuyerUnit;
use App\Actions\Buyers\SaveCostCenter;
use App\Data\Buyers\BuyerUnitData;
use App\Data\Buyers\CostCenterData;
use App\Domain\Buyers\Exceptions\BuyerUnitCodeTaken;
use App\Domain\Buyers\Exceptions\BuyerUnitMismatch;
use App\Domain\Buyers\Exceptions\CostCenterCodeTaken;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);
    $this->otherCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Engenharia Litoral']);

    $this->saveUnit = fn (Membership $actor, BuyerCompany $company, ?BuyerUnit $unit, BuyerUnitData $data): BuyerUnit => $this->tenant->actingAs(
        $actor,
        static fn (): BuyerUnit => app(SaveBuyerUnit::class)->handle($company, $unit, $data),
    );

    $this->saveCostCenter = fn (Membership $actor, BuyerCompany $company, ?CostCenter $costCenter, CostCenterData $data): CostCenter => $this->tenant->actingAs(
        $actor,
        static fn (): CostCenter => app(SaveCostCenter::class)->handle($company, $costCenter, $data),
    );

    $this->costCenters = fn (BuyerCompany $company): array => $this->tenant->system(static fn (): array => CostCenter::query()
        ->where('buyer_company_id', $company->id)
        ->orderBy('code')
        ->get()
        ->map(static fn (CostCenter $c): array => [$c->code, $c->name, $c->buyer_unit_id, $c->is_active])
        ->all());
});

it('cadastra unidade com código normalizado e auditoria vinculada à empresa', function (): void {
    $unit = ($this->saveUnit)($this->manager, $this->company, null, new BuyerUnitData(' obra-vm ', ' Obra Vila Mariana '));

    $stored = $this->tenant->system(static fn (): BuyerUnit => BuyerUnit::query()->findOrFail($unit->id));

    expect($stored->buyer_company_id)->toBe($this->company->id)
        ->and($stored->code)->toBe('OBRA-VM')
        ->and($stored->name)->toBe('Obra Vila Mariana')
        ->and($stored->is_active)->toBeTrue();

    $log = Fixtures::audits($this->tenant, 'buyer_unit.created')->sole();

    expect($log->subject_type)->toBe('buyer_unit')
        ->and($log->data['buyer_company_id'])->toBe($this->company->id)
        ->and($log->data['after'])->toEqual(['code' => 'OBRA-VM', 'name' => 'Obra Vila Mariana', 'is_active' => true]);
});

it('exige código de unidade único na empresa, permitindo o mesmo código em outra empresa', function (): void {
    ($this->saveUnit)($this->manager, $this->company, null, new BuyerUnitData('LOJA01', 'Loja Centro'));
    ($this->saveUnit)($this->manager, $this->otherCompany, null, new BuyerUnitData('LOJA01', 'Loja Porto'));

    expect(fn () => ($this->saveUnit)($this->manager, $this->company, null, new BuyerUnitData('loja01', 'Loja Centro 2')))
        ->toThrow(BuyerUnitCodeTaken::class, 'Já existe uma unidade com este código nesta empresa.');

    expect($this->tenant->system(static fn (): int => BuyerUnit::query()->count()))->toBe(2);
});

it('inativa unidade registrando o estado anterior', function (): void {
    $unit = Fixtures::unit($this->tenant, $this->company, 'PLANTA1', 'Planta Cubatão');

    ($this->saveUnit)($this->manager, $this->company, $unit, new BuyerUnitData('PLANTA1', 'Planta Cubatão', isActive: false));

    expect($this->tenant->system(static fn (): bool => BuyerUnit::query()->findOrFail($unit->id)->is_active))->toBeFalse()
        ->and(Fixtures::audits($this->tenant, 'buyer_unit.updated')->sole()->data['before']['is_active'])->toBeTrue();
});

it('não altera unidade informada por meio de outra empresa', function (): void {
    $unit = Fixtures::unit($this->tenant, $this->otherCompany, 'OBRA9', 'Obra Guarujá');

    expect(fn () => ($this->saveUnit)($this->manager, $this->company, $unit, new BuyerUnitData('OBRA9', 'Alterada indevidamente')))
        ->toThrow(ModelNotFoundException::class);

    expect($this->tenant->system(static fn (): string => BuyerUnit::query()->findOrFail($unit->id)->name))->toBe('Obra Guarujá');
});

it('cadastra centro de custo vinculado a unidade da mesma empresa', function (): void {
    $unit = Fixtures::unit($this->tenant, $this->company, 'OBRA-VM', 'Obra Vila Mariana');

    $costCenter = ($this->saveCostCenter)($this->manager, $this->company, null, new CostCenterData('cc-101', 'Estrutura e fundação', $unit->id));

    expect(($this->costCenters)($this->company))->toBe([['CC-101', 'Estrutura e fundação', $unit->id, true]])
        ->and(Fixtures::audits($this->tenant, 'cost_center.created')->sole()->subject_id)->toBe($costCenter->id);
});

it('recusa unidade de outra empresa no centro de custo', function (): void {
    $foreignUnit = Fixtures::unit($this->tenant, $this->otherCompany, 'OBRA9', 'Obra Guarujá');

    try {
        ($this->saveCostCenter)($this->manager, $this->company, null, new CostCenterData('CC-200', 'Acabamento', $foreignUnit->id));
        $this->fail('A unidade de outra empresa deveria ser recusada.');
    } catch (BuyerUnitMismatch $e) {
        expect($e->errorCode())->toBe('buyer_unit_mismatch')
            ->and($e->details())->toBe(['field' => 'buyer_unit_id']);
    }

    expect(($this->costCenters)($this->company))->toBe([]);
});

it('trata unidade de outro distribuidor como inexistente no centro de custo', function (): void {
    $other = TenantScenario::create();
    $foreignUnit = Fixtures::unit($other, $other->addBuyerCompany(), 'OBRA1', 'Obra externa');

    expect(fn () => ($this->saveCostCenter)($this->manager, $this->company, null, new CostCenterData('CC-300', 'Elétrica', $foreignUnit->id)))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->costCenters)($this->company))->toBe([]);
});

it('exige código de centro de custo único na empresa', function (): void {
    ($this->saveCostCenter)($this->manager, $this->company, null, new CostCenterData('CC-101', 'Estrutura'));
    ($this->saveCostCenter)($this->manager, $this->otherCompany, null, new CostCenterData('CC-101', 'Estrutura (outra empresa)'));

    expect(fn () => ($this->saveCostCenter)($this->manager, $this->company, null, new CostCenterData('cc-101', 'Repetido')))
        ->toThrow(CostCenterCodeTaken::class);

    expect(($this->costCenters)($this->company))->toHaveCount(1);
});

it('desvincula a unidade e inativa o centro de custo com auditoria', function (): void {
    $unit = Fixtures::unit($this->tenant, $this->company, 'OBRA-VM', 'Obra Vila Mariana');
    $costCenter = ($this->saveCostCenter)($this->manager, $this->company, null, new CostCenterData('CC-101', 'Estrutura', $unit->id));

    ($this->saveCostCenter)($this->manager, $this->company, $costCenter, new CostCenterData('CC-101', 'Estrutura', null, isActive: false));

    expect(($this->costCenters)($this->company))->toBe([['CC-101', 'Estrutura', null, false]])
        ->and(Fixtures::audits($this->tenant, 'cost_center.updated')->sole()->data['before']['buyer_unit_id'])->toBe($unit->id);
});

it('exige buyers.manage para unidades e centros de custo', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => ($this->saveUnit)($seller, $this->company, null, new BuyerUnitData('LOJA01', 'Loja Centro')))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->saveCostCenter)($seller, $this->company, null, new CostCenterData('CC-1', 'Geral')))
        ->toThrow(AuthorizationException::class);

    expect($this->tenant->system(static fn (): int => BuyerUnit::query()->count() + CostCenter::query()->count()))->toBe(0);
});

it('não cria unidade em empresa de outro distribuidor', function (): void {
    $other = TenantScenario::create();
    $foreign = $other->addBuyerCompany();

    expect(fn () => ($this->saveUnit)($this->manager, $foreign, null, new BuyerUnitData('LOJA01', 'Loja externa')))
        ->toThrow(ModelNotFoundException::class);

    expect($other->system(static fn (): int => BuyerUnit::query()->count()))->toBe(0);
});
