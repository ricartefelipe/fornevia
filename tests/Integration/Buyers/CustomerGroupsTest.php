<?php

declare(strict_types=1);

use App\Actions\Buyers\DeleteCustomerGroup;
use App\Actions\Buyers\SaveCustomerGroup;
use App\Data\Buyers\CustomerGroupData;
use App\Domain\Buyers\Exceptions\CustomerGroupCodeTaken;
use App\Domain\Buyers\Exceptions\CustomerGroupInUse;
use App\Domain\Buyers\Exceptions\InvalidRecordField;
use App\Enums\Identity\SystemRole;
use App\Enums\Pricing\PriceListScope;
use App\Models\Buyers\CustomerGroup;
use App\Models\Identity\Membership;
use App\Models\Pricing\PriceList;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->save = fn (Membership $actor, ?CustomerGroup $group, CustomerGroupData $data, ?TenantScenario $tenant = null): CustomerGroup => ($tenant ?? $this->tenant)->actingAs(
        $actor,
        static fn (): CustomerGroup => app(SaveCustomerGroup::class)->handle($group, $data),
    );

    $this->delete = fn (Membership $actor, CustomerGroup $group): null => $this->tenant->actingAs(
        $actor,
        static function () use ($group): null {
            app(DeleteCustomerGroup::class)->handle($group);

            return null;
        },
    );

    $this->groups = fn (?TenantScenario $tenant = null): array => ($tenant ?? $this->tenant)->system(
        static fn (): array => CustomerGroup::query()->orderBy('code')->pluck('name', 'code')->all(),
    );
});

it('cadastra grupo com código em maiúsculas e registra a auditoria', function (): void {
    $group = ($this->save)($this->manager, null, new CustomerGroupData('  construtoras-sp ', ' Construtoras de São Paulo ', '  '));

    expect(($this->groups)())->toBe(['CONSTRUTORAS-SP' => 'Construtoras de São Paulo'])
        ->and($this->tenant->system(static fn (): ?string => CustomerGroup::query()->findOrFail($group->id)->description))->toBeNull();

    $logs = Fixtures::audits($this->tenant, 'customer_group.created');

    expect($logs)->toHaveCount(1)
        ->and($logs[0]->subject_type)->toBe('customer_group')
        ->and($logs[0]->subject_id)->toBe($group->id)
        ->and($logs[0]->data)->toEqual([
            'before' => null,
            'after' => ['code' => 'CONSTRUTORAS-SP', 'name' => 'Construtoras de São Paulo', 'description' => null],
        ]);
});

it('recusa código repetido no distribuidor, sem diferenciar maiúsculas', function (): void {
    ($this->save)($this->manager, null, new CustomerGroupData('REVENDAS', 'Revendas'));

    try {
        ($this->save)($this->manager, null, new CustomerGroupData('revendas', 'Revendas do interior'));
        $this->fail('O código repetido deveria ser recusado.');
    } catch (CustomerGroupCodeTaken $e) {
        expect($e->errorCode())->toBe('customer_group_code_taken')
            ->and($e->details())->toBe(['field' => 'code']);
    }

    expect(($this->groups)())->toBe(['REVENDAS' => 'Revendas']);
});

it('aceita o mesmo código em outro distribuidor', function (): void {
    $other = TenantScenario::create();
    Fixtures::group($other, 'REVENDAS', 'Revendas do outro distribuidor');

    ($this->save)($this->manager, null, new CustomerGroupData('REVENDAS', 'Revendas'));

    expect(($this->groups)())->toBe(['REVENDAS' => 'Revendas'])
        ->and(($this->groups)($other))->toBe(['REVENDAS' => 'Revendas do outro distribuidor']);
});

it('recusa código com caracteres fora do padrão', function (string $code): void {
    expect(fn () => ($this->save)($this->manager, null, new CustomerGroupData($code, 'Grupo inválido')))
        ->toThrow(InvalidRecordField::class);

    expect(($this->groups)())->toBe([]);
})->with([
    'espaço interno' => ['GRUPO A'],
    'acentuado' => ['CONSTRUÇÃO'],
    'começando por hífen' => ['-REVENDA'],
    'longo demais' => [str_repeat('A', 31)],
]);

it('altera o grupo registrando estados anterior e posterior', function (): void {
    $group = Fixtures::group($this->tenant, 'REVENDAS', 'Revendas');

    ($this->save)($this->manager, $group, new CustomerGroupData('REVENDAS-SUL', 'Revendas da região Sul', 'PR, SC e RS'));

    expect(($this->groups)())->toBe(['REVENDAS-SUL' => 'Revendas da região Sul'])
        ->and(Fixtures::audits($this->tenant, 'customer_group.updated')[0]->data)->toEqual([
            'before' => ['code' => 'REVENDAS', 'name' => 'Revendas', 'description' => null],
            'after' => ['code' => 'REVENDAS-SUL', 'name' => 'Revendas da região Sul', 'description' => 'PR, SC e RS'],
        ]);
});

it('não altera nem exclui grupo de outro distribuidor', function (): void {
    $other = TenantScenario::create();
    $foreign = Fixtures::group($other, 'REVENDAS', 'Revendas');

    expect(fn () => ($this->save)($this->manager, $foreign, new CustomerGroupData('INVADIDO', 'Alterado indevidamente')))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => ($this->delete)($this->manager, $foreign))
        ->toThrow(ModelNotFoundException::class);

    expect(($this->groups)($other))->toBe(['REVENDAS' => 'Revendas']);
});

it('exige buyers.manage para cadastrar, alterar e excluir grupos', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $group = Fixtures::group($this->tenant, 'REVENDAS', 'Revendas');

    expect(fn () => ($this->save)($actor, null, new CustomerGroupData('ATACADO', 'Atacado')))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($actor, $group, new CustomerGroupData('REVENDAS', 'Alterado')))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->delete)($actor, $group))->toThrow(AuthorizationException::class);

    expect(($this->groups)())->toBe(['REVENDAS' => 'Revendas']);
})->with([
    'representante comercial' => [SystemRole::SalesRep],
    'consulta' => [SystemRole::Viewer],
]);

it('exclui grupo sem uso e registra os dados removidos', function (): void {
    $group = Fixtures::group($this->tenant, 'EVENTUAIS', 'Clientes eventuais');

    ($this->delete)($this->manager, $group);

    expect(($this->groups)())->toBe([])
        ->and(Fixtures::audits($this->tenant, 'customer_group.deleted')[0]->data)->toEqual([
            'before' => ['code' => 'EVENTUAIS', 'name' => 'Clientes eventuais', 'description' => null],
        ]);
});

it('não exclui grupo com empresas vinculadas', function (): void {
    $group = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $this->tenant->addBuyerCompany(['customer_group_id' => $group->id]);

    try {
        ($this->delete)($this->manager, $group);
        $this->fail('O grupo em uso não deveria ser excluído.');
    } catch (CustomerGroupInUse $e) {
        expect($e->errorCode())->toBe('customer_group_in_use')
            ->and($e->details()['usage'])->toBe(['buyer_companies' => 1, 'price_lists' => 0, 'private_catalogs' => 0]);
    }

    expect(($this->groups)())->toBe(['CONSTRUTORAS' => 'Construtoras'])
        ->and(Fixtures::audits($this->tenant, 'customer_group.deleted'))->toBeEmpty();
});

it('não exclui grupo com tabela de preço vinculada', function (): void {
    $group = Fixtures::group($this->tenant, 'REVENDAS', 'Revendas');
    $this->tenant->system(static function () use ($group): void {
        $list = new PriceList;
        $list->forceFill([
            'code' => 'TAB-REVENDAS',
            'name' => 'Tabela de revendas',
            'scope' => PriceListScope::Group,
            'customer_group_id' => $group->id,
            'valid_from' => '2026-11-01',
        ])->save();
    });

    expect(fn () => ($this->delete)($this->manager, $group))->toThrow(CustomerGroupInUse::class);

    expect(($this->groups)())->toBe(['REVENDAS' => 'Revendas']);
});
