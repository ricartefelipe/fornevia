<?php

declare(strict_types=1);

use App\Actions\Buyers\SaveBuyerAddress;
use App\Data\Buyers\BuyerAddressData;
use App\Domain\Buyers\Exceptions\BuyerUnitMismatch;
use App\Domain\Buyers\Exceptions\InvalidAddress;
use App\Enums\Buyers\AddressKind;
use App\Enums\Identity\SystemRole;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);

    $this->save = fn (Membership $actor, BuyerCompany $company, ?BuyerAddress $address, BuyerAddressData $data): BuyerAddress => $this->tenant->actingAs(
        $actor,
        static fn (): BuyerAddress => app(SaveBuyerAddress::class)->handle($company, $address, $data),
    );

    $this->defaults = fn (?BuyerCompany $company = null): array => Fixtures::addresses($this->tenant, $company ?? $this->company)
        ->mapWithKeys(static fn (BuyerAddress $a): array => [$a->label => [$a->kind->value, $a->is_default, $a->is_active]])
        ->all();
});

it('cadastra endereço com CEP só com dígitos, UF em maiúsculas e auditoria', function (): void {
    $address = ($this->save)($this->manager, $this->company, null, Fixtures::addressData([
        'postalCode' => ' 04010-100 ',
        'state' => 'sp',
        'complement' => '  ',
    ]));

    $stored = Fixtures::addresses($this->tenant, $this->company)->sole();

    expect($stored->id)->toBe($address->id)
        ->and($stored->postal_code)->toBe('04010100')
        ->and($stored->state)->toBe('SP')
        ->and($stored->complement)->toBeNull()
        ->and($stored->kind)->toBe(AddressKind::Shipping)
        ->and($stored->is_default)->toBeFalse();

    $log = Fixtures::audits($this->tenant, 'buyer_address.created')->sole();

    expect($log->subject_type)->toBe('buyer_address')
        ->and($log->data['buyer_company_id'])->toBe($this->company->id)
        ->and($log->data['after']['postal_code'])->toBe('04010100')
        ->and($log->data['after']['kind'])->toBe('shipping');
});

it('recusa CEP que não tenha 8 dígitos', function (string $postalCode): void {
    try {
        ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['postalCode' => $postalCode]));
        $this->fail('O CEP deveria ser recusado.');
    } catch (InvalidAddress $e) {
        expect($e->errorCode())->toBe('invalid_address')
            ->and($e->details())->toBe(['field' => 'postal_code']);
    }

    expect(Fixtures::addresses($this->tenant, $this->company))->toBeEmpty();
})->with([
    'sete dígitos' => ['0401010'],
    'nove dígitos' => ['040101001'],
    'letras' => ['0401O-100'],
    'zeros' => ['00000-000'],
    'separador inválido' => ['04.010-100'],
]);

it('recusa UF inexistente', function (string $state): void {
    try {
        ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['state' => $state]));
        $this->fail('A UF deveria ser recusada.');
    } catch (InvalidAddress $e) {
        expect($e->details())->toBe(['field' => 'state']);
    }

    expect(Fixtures::addresses($this->tenant, $this->company))->toBeEmpty();
})->with([['XX'], ['S'], ['São Paulo'], ['']]);

it('mantém um único endereço padrão por tipo, desmarcando o anterior do mesmo tipo', function (): void {
    $billing = ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['kind' => AddressKind::Billing, 'label' => 'Sede', 'isDefault' => true]));
    ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['label' => 'Obra Vila Mariana', 'isDefault' => true]));

    $newBilling = ($this->save)($this->manager, $this->company, null, Fixtures::addressData([
        'kind' => AddressKind::Billing,
        'label' => 'Escritório Paulista',
        'isDefault' => true,
    ]));

    expect(($this->defaults)())->toBe([
        'Sede' => ['billing', false, true],
        'Obra Vila Mariana' => ['shipping', true, true],
        'Escritório Paulista' => ['billing', true, true],
    ]);

    $log = Fixtures::audits($this->tenant, 'buyer_address.created')->last();

    expect($log->subject_id)->toBe($newBilling->id)
        ->and($log->data['replaced_default_ids'])->toBe([$billing->id]);
});

it('ao trocar o tipo de um endereço padrão, substitui o padrão do novo tipo', function (): void {
    ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['kind' => AddressKind::Billing, 'label' => 'Sede', 'isDefault' => true]));
    $shipping = ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['label' => 'Depósito', 'isDefault' => true]));

    ($this->save)($this->manager, $this->company, $shipping, Fixtures::addressData(['kind' => AddressKind::Billing, 'label' => 'Depósito', 'isDefault' => true]));

    expect(($this->defaults)())->toBe([
        'Sede' => ['billing', false, true],
        'Depósito' => ['billing', true, true],
    ]);
});

it('não mantém como padrão um endereço inativo nem altera o padrão vigente', function (): void {
    ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['label' => 'Obra Vila Mariana', 'isDefault' => true]));
    $old = ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['label' => 'Obra encerrada']));

    ($this->save)($this->manager, $this->company, $old, Fixtures::addressData(['label' => 'Obra encerrada', 'isDefault' => true, 'isActive' => false]));

    expect(($this->defaults)())->toBe([
        'Obra Vila Mariana' => ['shipping', true, true],
        'Obra encerrada' => ['shipping', false, false],
    ]);
});

it('isola o endereço padrão por empresa', function (): void {
    $otherCompany = $this->tenant->addBuyerCompany();
    ($this->save)($this->manager, $otherCompany, null, Fixtures::addressData(['label' => 'Outra empresa', 'isDefault' => true]));
    ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['label' => 'Obra Vila Mariana', 'isDefault' => true]));

    expect(($this->defaults)($otherCompany))->toBe(['Outra empresa' => ['shipping', true, true]])
        ->and(($this->defaults)())->toBe(['Obra Vila Mariana' => ['shipping', true, true]]);
});

it('vincula unidade da mesma empresa e recusa unidade de outra empresa', function (): void {
    $unit = Fixtures::unit($this->tenant, $this->company, 'OBRA-VM', 'Obra Vila Mariana');
    $foreignUnit = Fixtures::unit($this->tenant, $this->tenant->addBuyerCompany(), 'OBRA-X', 'Obra de outra empresa');

    $address = ($this->save)($this->manager, $this->company, null, Fixtures::addressData(['buyerUnitId' => $unit->id]));

    expect(Fixtures::addresses($this->tenant, $this->company)->sole()->buyer_unit_id)->toBe($unit->id)
        ->and(fn () => ($this->save)($this->manager, $this->company, $address, Fixtures::addressData(['buyerUnitId' => $foreignUnit->id])))
        ->toThrow(BuyerUnitMismatch::class);

    expect(Fixtures::addresses($this->tenant, $this->company)->sole()->buyer_unit_id)->toBe($unit->id);
});

it('não altera endereço informado por meio de outra empresa ou de outro distribuidor', function (): void {
    $otherCompany = $this->tenant->addBuyerCompany();
    $address = ($this->save)($this->manager, $otherCompany, null, Fixtures::addressData(['label' => 'Original']));

    $other = TenantScenario::create();
    $foreignCompany = $other->addBuyerCompany();

    expect(fn () => ($this->save)($this->manager, $this->company, $address, Fixtures::addressData(['label' => 'Alterado'])))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->save)($this->manager, $foreignCompany, null, Fixtures::addressData()))
        ->toThrow(ModelNotFoundException::class);

    expect(Fixtures::addresses($this->tenant, $otherCompany)->sole()->label)->toBe('Original')
        ->and(Fixtures::addresses($other, $foreignCompany))->toBeEmpty();
});

it('exige buyers.manage para endereços', function (): void {
    $viewer = $this->tenant->addStaff(SystemRole::Viewer);

    expect(fn () => ($this->save)($viewer, $this->company, null, Fixtures::addressData()))
        ->toThrow(AuthorizationException::class);

    expect(Fixtures::addresses($this->tenant, $this->company))->toBeEmpty();
});
