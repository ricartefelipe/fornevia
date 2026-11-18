<?php

declare(strict_types=1);

use App\Actions\Catalog\SaveUnitOfMeasure;
use App\Data\Catalog\UnitOfMeasureData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Catalog\Exceptions\UnitDecimalsInUse;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->save = fn (TenantScenario $tenant, Membership $actor, ?UnitOfMeasure $unit, UnitOfMeasureData $data): UnitOfMeasure => $tenant->actingAs(
        $actor,
        static fn (): UnitOfMeasure => app(SaveUnitOfMeasure::class)->handle($unit, $data),
    );

    $this->stored = fn (TenantScenario $tenant, string $id): UnitOfMeasure => $tenant->system(
        static fn (): UnitOfMeasure => UnitOfMeasure::query()->findOrFail($id),
    );
});

it('cadastra unidade com código em maiúsculas e audita', function (): void {
    $tenant = TenantScenario::create();

    $unit = ($this->save)($tenant, $tenant->ownerMembership, null, new UnitOfMeasureData(' fd ', ' Fardo ', 0));
    $stored = ($this->stored)($tenant, $unit->id);

    expect($stored->code)->toBe('FD')
        ->and($stored->name)->toBe('Fardo')
        ->and($stored->decimals)->toBe(0)
        ->and(Fixtures::audits($tenant, 'unit_of_measure.created')->first()?->data)->toEqual([
            'before' => null,
            'after' => ['code' => 'FD', 'name' => 'Fardo', 'decimals' => 0],
        ]);
});

it('recusa código repetido no tenant e aceita o mesmo código em outro tenant', function (): void {
    $aurora = TenantScenario::create();
    $boreal = TenantScenario::create();
    Fixtures::unit($aurora, 'GL', 0, 'Galão');

    try {
        ($this->save)($aurora, $aurora->ownerMembership, null, new UnitOfMeasureData('gl', 'Galão 5 L', 0));
        $this->fail('Código repetido deveria ser recusado.');
    } catch (CatalogIdentifierInUse $e) {
        expect($e->errorCode())->toBe('catalog_identifier_in_use')
            ->and($e->field())->toBe('code')
            ->and($e->getMessage())->toBe('Já existe uma unidade de medida com o código GL.');
    }

    $foreign = ($this->save)($boreal, $boreal->ownerMembership, null, new UnitOfMeasureData('GL', 'Galão', 0));

    expect($foreign->code)->toBe('GL')
        ->and($aurora->system(static fn (): array => UnitOfMeasure::query()->where('code', 'GL')->pluck('name')->all()))->toBe(['Galão']);
});

it('valida código, nome e casas decimais', function (string $code, string $name, int $decimals, string $field): void {
    $tenant = TenantScenario::create();

    try {
        ($this->save)($tenant, $tenant->ownerMembership, null, new UnitOfMeasureData($code, $name, $decimals));
        $this->fail('Dados inválidos deveriam ser recusados.');
    } catch (InvalidCatalogData $e) {
        expect($e->fieldName())->toBe($field);
    }
})->with([
    'código com espaço' => ['C X', 'Caixa', 0, 'code'],
    'código longo' => ['UNIDADEGRANDE', 'Unidade', 0, 'code'],
    'nome vazio' => ['UN', '', 0, 'name'],
    'casas acima de 3' => ['KG', 'Quilograma', 4, 'decimals'],
    'casas negativas' => ['KG', 'Quilograma', -1, 'decimals'],
]);

it('recusa reduzir casas decimais quando SKUs vendidos na unidade ficariam incompatíveis', function (): void {
    $tenant = TenantScenario::create();
    $kg = Fixtures::unit($tenant, 'KG', 3);
    $product = Fixtures::product($tenant, $kg, ['name' => 'Açúcar cristal a granel']);
    Fixtures::sku($tenant, $product, $kg, 'ACU-GRANEL', ['min_order_quantity' => Quantity::of('0,5'), 'order_multiple' => Quantity::of('0,25')]);

    try {
        ($this->save)($tenant, $tenant->ownerMembership, $kg, new UnitOfMeasureData('KG', 'Quilograma', 1));
        $this->fail('A redução de casas decimais deveria ser recusada.');
    } catch (UnitDecimalsInUse $e) {
        expect($e->errorCode())->toBe('unit_decimals_in_use')
            ->and($e->details())->toBe(['sku_codes' => ['ACU-GRANEL']]);
    }

    expect(($this->stored)($tenant, $kg->id)->decimals)->toBe(3);

    // Duas casas ainda comportam 0,5 e 0,25.
    ($this->save)($tenant, $tenant->ownerMembership, $kg, new UnitOfMeasureData('KG', 'Quilograma', 2));
    expect(($this->stored)($tenant, $kg->id)->decimals)->toBe(2);
});

it('exige catalog.manage', function (SystemRole $role): void {
    $tenant = TenantScenario::create();
    $actor = $tenant->addStaff($role);

    $kg = Fixtures::unit($tenant, 'KG');

    expect(fn () => ($this->save)($tenant, $actor, null, new UnitOfMeasureData('FD', 'Fardo', 0)))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($tenant, $actor, $kg, new UnitOfMeasureData('KG', 'Quilo', 0)))
        ->toThrow(AuthorizationException::class);

    expect($tenant->system(static fn (): int => UnitOfMeasure::query()->where('code', 'FD')->count()))->toBe(0)
        ->and(($this->stored)($tenant, $kg->id)->decimals)->toBe(3);
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'estoque' => [SystemRole::Warehouse],
    'consulta' => [SystemRole::Viewer],
]);

it('não altera unidade de outro tenant', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $foreign = Fixtures::unit($other, 'UN');

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, $foreign, new UnitOfMeasureData('UN', 'Unidade alterada', 2)))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->stored)($other, $foreign->id)->decimals)->toBe(0);
});
