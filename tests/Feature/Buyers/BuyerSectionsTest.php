<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Buyers\BuyerAddresses;
use App\Livewire\Staff\Buyers\BuyerContacts;
use App\Livewire\Staff\Buyers\BuyerCostCenters;
use App\Livewire\Staff\Buyers\BuyerHistory;
use App\Livewire\Staff\Buyers\BuyerPaymentTerms;
use App\Livewire\Staff\Buyers\BuyerUnits;
use App\Livewire\Staff\Buyers\BuyerUsers;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerContact;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Models\Pricing\BuyerPaymentTerm;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);
    $this->otherCompany = $this->tenant->addBuyerCompany(['trade_name' => 'Engenharia Litoral']);
    $this->as = fn (Closure $callback, mixed $actor = null): mixed => Fixtures::livewireAs($this->tenant, $actor ?? $this->manager, $callback);
});

it('não monta seções para empresa de outro distribuidor ou identificador malformado', function (string $component): void {
    $foreign = TenantScenario::create()->addBuyerCompany();

    ($this->as)(static function () use ($component, $foreign): void {
        Livewire::test($component, ['buyerId' => $foreign->id])->assertNotFound();
        Livewire::test($component, ['buyerId' => 'empresa-qualquer'])->assertNotFound();
    });
})->with([
    'contatos' => [BuyerContacts::class],
    'endereços' => [BuyerAddresses::class],
    'unidades' => [BuyerUnits::class],
    'centros de custo' => [BuyerCostCenters::class],
    'condições de pagamento' => [BuyerPaymentTerms::class],
    'usuários' => [BuyerUsers::class],
    'histórico' => [BuyerHistory::class],
]);

it('protege o identificador da empresa nas seções', function (): void {
    ($this->as)(function (): void {
        $component = Livewire::test(BuyerContacts::class, ['buyerId' => $this->company->id]);

        expect(fn () => $component->set('buyerId', $this->otherCompany->id))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $component->set('editingId', $this->otherCompany->id))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('cadastra, edita e remove contatos pela seção', function (): void {
    ($this->as)(function (): void {
        $component = Livewire::test(BuyerContacts::class, ['buyerId' => $this->company->id])
            ->assertSee('Nenhum contato cadastrado.')
            ->call('create')
            ->set('name', 'Patrícia Nogueira')
            ->set('email', 'patricia@')
            ->call('save')
            ->assertHasErrors(['email' => 'email'])
            ->set('email', 'patricia.nogueira@serraazul.example')
            ->set('isPrimary', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Contato cadastrado.')
            ->assertSet('formOpen', false)
            ->assertDispatched('buyer-updated')
            ->assertSee('Patrícia Nogueira')
            ->assertSee('Principal');

        $contact = BuyerContact::query()->where('buyer_company_id', $this->company->id)->sole();

        $component->call('edit', $contact->id)
            ->assertSet('editingId', $contact->id)
            ->set('position', 'Gerente de suprimentos')
            ->call('save')
            ->assertSet('message', 'Contato atualizado.')
            ->call('delete', $contact->id)
            ->assertSet('message', 'Contato removido.')
            ->assertSee('Nenhum contato cadastrado.');
    });

    expect(Fixtures::audits($this->tenant, 'buyer_contact.created'))->toHaveCount(1)
        ->and(Fixtures::audits($this->tenant, 'buyer_contact.updated'))->toHaveCount(1)
        ->and(Fixtures::audits($this->tenant, 'buyer_contact.deleted'))->toHaveCount(1);
});

it('trata contato de outra empresa ou de outro distribuidor como inexistente', function (): void {
    $sibling = $this->tenant->system(function (): BuyerContact {
        $contact = new BuyerContact;
        $contact->forceFill(['buyer_company_id' => $this->otherCompany->id, 'name' => 'Contato da outra empresa'])->save();

        return $contact;
    });
    $other = TenantScenario::create();
    $foreignCompany = $other->addBuyerCompany();
    $foreign = $other->system(static function () use ($foreignCompany): BuyerContact {
        $contact = new BuyerContact;
        $contact->forceFill(['buyer_company_id' => $foreignCompany->id, 'name' => 'Contato externo'])->save();

        return $contact;
    });

    ($this->as)(function () use ($sibling, $foreign): void {
        foreach ([$sibling->id, $foreign->id, "{$sibling->id}' --"] as $id) {
            Livewire::test(BuyerContacts::class, ['buyerId' => $this->company->id])->call('edit', $id)->assertNotFound();
            Livewire::test(BuyerContacts::class, ['buyerId' => $this->company->id])->call('delete', $id)->assertNotFound();
        }
    });

    expect($this->tenant->system(static fn (): int => BuyerContact::query()->count()))->toBe(1)
        ->and($other->system(static fn (): int => BuyerContact::query()->count()))->toBe(1);
});

it('cadastra endereço pela seção e exibe erros de CEP, UF e unidade nos campos', function (): void {
    $unit = Fixtures::unit($this->tenant, $this->company, 'OBRA-VM', 'Obra Vila Mariana');
    $siblingUnit = Fixtures::unit($this->tenant, $this->otherCompany, 'OBRA-X', 'Obra da outra empresa');

    ($this->as)(function () use ($unit, $siblingUnit): void {
        Livewire::test(BuyerAddresses::class, ['buyerId' => $this->company->id])
            ->call('create')
            ->assertSee('Obra Vila Mariana (OBRA-VM)')
            ->assertDontSee('Obra da outra empresa')
            ->set('kind', 'billing')
            ->set('label', 'Sede')
            ->set('street', 'Avenida Paulista')
            ->set('number', '1578')
            ->set('district', 'Bela Vista')
            ->set('city', 'São Paulo')
            ->set('state', 'SP')
            ->set('postalCode', '0131-100')
            ->call('save')
            ->assertHasErrors(['postalCode' => 'Informe um CEP válido com 8 dígitos.'])
            ->set('postalCode', '01310-100')
            ->set('state', 'XX')
            ->call('save')
            ->assertHasErrors(['state' => 'Informe uma UF válida.'])
            ->set('state', 'SP')
            ->set('buyerUnitId', $siblingUnit->id)
            ->call('save')
            ->assertHasErrors(['buyerUnitId' => 'A unidade informada não pertence a esta empresa compradora.'])
            ->set('buyerUnitId', $unit->id)
            ->set('isDefault', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Endereço cadastrado.')
            ->assertSee('CEP 01310-100')
            ->assertSee('Padrão');
    });

    $stored = Fixtures::addresses($this->tenant, $this->company)->sole();

    expect($stored->postal_code)->toBe('01310100')
        ->and($stored->buyer_unit_id)->toBe($unit->id)
        ->and($stored->is_default)->toBeTrue();
});

it('trata endereço de outra empresa como inexistente na edição', function (): void {
    $address = $this->tenant->system(function (): BuyerAddress {
        $address = new BuyerAddress;
        $address->forceFill([
            'buyer_company_id' => $this->otherCompany->id, 'kind' => 'billing', 'label' => 'Sede da outra empresa',
            'street' => 'Rua das Flores', 'number' => '10', 'district' => 'Centro', 'city' => 'Santos',
            'state' => 'SP', 'postal_code' => '11010000',
        ])->save();

        return $address;
    });

    ($this->as)(fn () => Livewire::test(BuyerAddresses::class, ['buyerId' => $this->company->id])
        ->call('edit', $address->id)
        ->assertNotFound());
});

it('cadastra unidade e centro de custo pela seção, com código repetido indicado no campo', function (): void {
    ($this->as)(function (): void {
        Livewire::test(BuyerUnits::class, ['buyerId' => $this->company->id])
            ->call('create')
            ->set('code', 'obra-vm')
            ->set('name', 'Obra Vila Mariana')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Unidade cadastrada.')
            ->assertSee('OBRA-VM')
            ->call('create')
            ->set('code', 'OBRA-VM')
            ->set('name', 'Repetida')
            ->call('save')
            ->assertHasErrors(['code' => 'Já existe uma unidade com este código nesta empresa.'])
            ->set('code', 'obra vm')
            ->call('save')
            ->assertHasErrors(['code' => 'Código deve conter apenas letras, números, ponto, hífen ou sublinhado.']);

        $unit = BuyerUnit::query()->where('buyer_company_id', $this->company->id)->sole();

        Livewire::test(BuyerCostCenters::class, ['buyerId' => $this->company->id])
            ->call('create')
            ->assertSee('Obra Vila Mariana (OBRA-VM)')
            ->set('code', 'CC-101')
            ->set('name', 'Estrutura e fundação')
            ->set('buyerUnitId', $unit->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Centro de custo cadastrado.')
            ->assertSee('Estrutura e fundação');
    });

    expect($this->tenant->system(fn (): ?string => CostCenter::query()->where('buyer_company_id', $this->company->id)->sole()->buyer_unit_id))
        ->not->toBeNull();
});

it('trata unidade e centro de custo de outra empresa como inexistentes na edição', function (): void {
    $unit = Fixtures::unit($this->tenant, $this->otherCompany, 'OBRA-X', 'Obra da outra empresa');
    $costCenter = $this->tenant->system(function (): CostCenter {
        $costCenter = new CostCenter;
        $costCenter->forceFill(['buyer_company_id' => $this->otherCompany->id, 'code' => 'CC-9', 'name' => 'Outro'])->save();

        return $costCenter;
    });

    ($this->as)(function () use ($unit, $costCenter): void {
        Livewire::test(BuyerUnits::class, ['buyerId' => $this->company->id])->call('edit', $unit->id)->assertNotFound();
        Livewire::test(BuyerCostCenters::class, ['buyerId' => $this->company->id])->call('edit', $costCenter->id)->assertNotFound();
    });
});

it('define condições de pagamento pela seção e exige a padrão na lista', function (): void {
    $cash = Fixtures::paymentTerm($this->tenant, 'AVISTA');
    $days28 = Fixtures::paymentTerm($this->tenant, '28D');

    ($this->as)(function () use ($cash, $days28): void {
        Livewire::test(BuyerPaymentTerms::class, ['buyerId' => $this->company->id])
            ->assertSee('Nenhuma condição de pagamento permitida.')
            ->call('startEditing')
            ->assertSee('30/60/90 dias')
            ->set('selectedTermIds', [$cash->id, $days28->id])
            ->call('save')
            ->assertHasErrors(['defaultTermId' => 'Escolha a condição de pagamento padrão.'])
            ->set('selectedTermIds', [$cash->id])
            ->set('defaultTermId', $days28->id)
            ->call('save')
            ->assertHasErrors(['defaultTermId' => 'A condição padrão precisa estar entre as condições permitidas.'])
            ->set('selectedTermIds', [$cash->id, $days28->id])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', false)
            ->assertSet('message', 'Condições de pagamento atualizadas.')
            ->assertSee('28 dias')
            ->assertSee('Padrão');
    });

    expect($this->tenant->system(fn (): array => BuyerPaymentTerm::query()
        ->where('buyer_company_id', $this->company->id)
        ->pluck('is_default', 'payment_term_id')
        ->all()))->toEqual([$cash->id => false, $days28->id => true]);
});

it('trata condição de pagamento de outro distribuidor ou malformada como inexistente', function (): void {
    $cash = Fixtures::paymentTerm($this->tenant, 'AVISTA');
    $foreign = Fixtures::paymentTerm(TenantScenario::create(), '28D');

    ($this->as)(function () use ($cash, $foreign): void {
        Livewire::test(BuyerPaymentTerms::class, ['buyerId' => $this->company->id])
            ->call('startEditing')
            ->set('selectedTermIds', [$cash->id, $foreign->id])
            ->set('defaultTermId', $cash->id)
            ->call('save')
            ->assertNotFound();

        Livewire::test(BuyerPaymentTerms::class, ['buyerId' => $this->company->id])
            ->call('startEditing')
            ->set('selectedTermIds', [$cash->id, 'AVISTA'])
            ->set('defaultTermId', $cash->id)
            ->call('save')
            ->assertHasErrors(['selectedTermIds.1' => 'uuid']);
    });

    expect($this->tenant->system(static fn (): int => BuyerPaymentTerm::query()->count()))->toBe(0);
});

it('refaz a autorização nas ações das seções', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    $cash = Fixtures::paymentTerm($this->tenant, 'AVISTA');

    ($this->as)(function () use ($cash): void {
        Livewire::test(BuyerContacts::class, ['buyerId' => $this->company->id])
            ->assertDontSee('Adicionar contato')
            ->set('name', 'Sem permissão')
            ->call('save')
            ->assertForbidden();

        Livewire::test(BuyerUnits::class, ['buyerId' => $this->company->id])
            ->set('code', 'LOJA01')
            ->set('name', 'Sem permissão')
            ->call('save')
            ->assertForbidden();

        Livewire::test(BuyerPaymentTerms::class, ['buyerId' => $this->company->id])
            ->set('selectedTermIds', [$cash->id])
            ->set('defaultTermId', $cash->id)
            ->call('save')
            ->assertForbidden();
    }, $seller);

    expect($this->tenant->system(static fn (): int => BuyerContact::query()->count() + BuyerUnit::query()->count() + BuyerPaymentTerm::query()->count()))
        ->toBe(0);
});

it('exibe no histórico os registros da empresa e dos seus cadastros, sem os de outras empresas', function (): void {
    ($this->as)(function (): void {
        Livewire::test(BuyerUnits::class, ['buyerId' => $this->company->id])
            ->call('create')->set('code', 'LOJA01')->set('name', 'Loja Centro')->call('save');
        Livewire::test(BuyerUnits::class, ['buyerId' => $this->otherCompany->id])
            ->call('create')->set('code', 'FILIAL-PORTO')->set('name', 'Unidade do porto')->call('save');
        Livewire::test(BuyerContacts::class, ['buyerId' => $this->company->id])
            ->call('create')->set('name', 'Patrícia Nogueira')->call('save');

        $history = Livewire::test(BuyerHistory::class, ['buyerId' => $this->company->id])
            ->assertSee('Unidade cadastrada')
            ->assertSee('Contato cadastrado');

        expect($history->viewData('entries'))->toHaveCount(2);

        Livewire::test(BuyerHistory::class, ['buyerId' => $this->otherCompany->id])
            ->assertSee('Unidade cadastrada')
            ->assertDontSee('Contato cadastrado');
    });
});

it('limita o histórico aos 50 registros mais recentes', function (): void {
    ($this->as)(function (): void {
        $units = Livewire::test(BuyerUnits::class, ['buyerId' => $this->company->id]);

        foreach (range(1, 52) as $i) {
            $units->call('create')->set('code', "U{$i}")->set('name', "Unidade {$i}")->call('save');
        }

        expect(Livewire::test(BuyerHistory::class, ['buyerId' => $this->company->id])->viewData('entries'))->toHaveCount(50);
    });
});
