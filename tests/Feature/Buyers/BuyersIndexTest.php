<?php

declare(strict_types=1);

use App\Domain\Shared\Cnpj;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Buyers\BuyersIndex;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\User;
use Database\Factories\Support\BrazilianDocuments;
use Livewire\Livewire;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    $this->companies = fn (?TenantScenario $tenant = null): array => ($tenant ?? $this->tenant)->system(
        static fn (): array => BuyerCompany::query()->orderBy('trade_name')->pluck('trade_name')->all(),
    );
});

it('nega a listagem a quem não tem buyers.view', function (): void {
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::Warehouse);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.index'))
        ->assertForbidden();
});

it('nega a listagem no contexto de empresa compradora', function (): void {
    $user = User::factory()->create()->fresh();
    $membership = $this->tenant->addBuyerUser($this->tenant->addBuyerCompany(), SystemRole::BuyerAdmin, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.index'))
        ->assertForbidden();
});

it('exibe as empresas ao representante sem oferecer o cadastro', function (): void {
    $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::SalesRep);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.index'))
        ->assertOk()
        ->assertSee('Empresas compradoras')
        ->assertSee('Construtora Serra Azul')
        ->assertSee(route('buyers.groups'), escape: false)
        ->assertDontSee('Nova empresa');
});

it('oferece o cadastro ao gerente comercial e exibe estado vazio', function (): void {
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::SalesManager);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.index'))
        ->assertOk()
        ->assertSee('Nova empresa')
        ->assertSee('Nenhuma empresa compradora cadastrada');
});

it('não lista empresas de outro distribuidor', function (): void {
    TenantScenario::create()->addBuyerCompany(['trade_name' => 'Empresa de outro distribuidor']);
    $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);

    Fixtures::livewireAs($this->tenant, $this->manager, static fn () => Livewire::test(BuyersIndex::class)
        ->assertSee('Construtora Serra Azul')
        ->assertDontSee('Empresa de outro distribuidor'));
});

it('busca por nome e por CNPJ, com ou sem pontuação', function (): void {
    $document = BrazilianDocuments::cnpj();
    $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul', 'legal_name' => 'Serra Azul Engenharia Ltda']);
    $this->tenant->addBuyerCompany(['trade_name' => 'Elétrica Paulista', 'legal_name' => 'Elétrica Paulista Comércio S.A.', 'document' => $document]);

    Fixtures::livewireAs($this->tenant, $this->manager, static function () use ($document): void {
        Livewire::test(BuyersIndex::class)
            ->set('search', 'engenharia')
            ->assertSee('Construtora Serra Azul')
            ->assertDontSee('Elétrica Paulista')
            ->set('search', Cnpj::format($document))
            ->assertSee('Elétrica Paulista')
            ->assertDontSee('Construtora Serra Azul')
            ->set('search', substr($document, 0, 8))
            ->assertSee('Elétrica Paulista')
            ->assertDontSee('Construtora Serra Azul')
            ->set('search', '%')
            ->assertSee('Nenhuma empresa encontrada')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSee('Construtora Serra Azul')
            ->assertSee('Elétrica Paulista');
    });
});

it('filtra pela situação e ignora situação desconhecida', function (): void {
    $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);
    $this->tenant->addBuyerCompany(['trade_name' => 'Madeireira Ipê', 'status' => BuyerCompanyStatus::Blocked]);

    Fixtures::livewireAs($this->tenant, $this->manager, static function (): void {
        Livewire::test(BuyersIndex::class)
            ->set('status', 'blocked')
            ->assertSee('Madeireira Ipê')
            ->assertDontSee('Construtora Serra Azul')
            ->set('status', 'qualquer')
            ->assertSee('Madeireira Ipê')
            ->assertSee('Construtora Serra Azul');
    });
});

it('pagina a listagem com 25 empresas por página', function (): void {
    foreach (range(1, 27) as $i) {
        $this->tenant->addBuyerCompany(['trade_name' => sprintf('Cliente %02d Materiais', $i)]);
    }

    Fixtures::livewireAs($this->tenant, $this->manager, static function (): void {
        $component = Livewire::test(BuyersIndex::class);
        $page = $component->viewData('companies');

        expect($page->total())->toBe(27)
            ->and($page->count())->toBe(25)
            ->and($page->perPage())->toBe(25);

        $component->assertSee('Cliente 01 Materiais')->assertDontSee('Cliente 26 Materiais')
            ->call('gotoPage', 2)
            ->assertSee('Cliente 26 Materiais')
            ->assertDontSee('Cliente 01 Materiais');
    });
});

it('cadastra a empresa pela tela e redireciona para a página dela', function (): void {
    $group = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $seller = $this->tenant->addStaff(SystemRole::SalesRep, User::factory()->create(['name' => 'Rafael Antunes Moreira']));
    $document = BrazilianDocuments::cnpj();

    $component = Fixtures::livewireAs($this->tenant, $this->manager, static fn () => Livewire::test(BuyersIndex::class)
        ->call('openForm')
        ->assertSee('Rafael Antunes Moreira')
        ->set('form.legalName', 'Construtora Horizonte Verde Ltda')
        ->set('form.tradeName', 'Horizonte Verde')
        ->set('form.document', Cnpj::format($document))
        ->set('form.email', 'compras@horizonteverde.example')
        ->set('form.customerGroupId', $group->id)
        ->set('form.salesRepMembershipId', $seller->id)
        ->call('save')
        ->assertHasNoErrors());

    $stored = $this->tenant->system(static fn (): BuyerCompany => BuyerCompany::query()->where('document', $document)->sole());

    $component->assertRedirect(route('buyers.show', ['buyer' => $stored->id]));

    expect($stored->trade_name)->toBe('Horizonte Verde')
        ->and($stored->customer_group_id)->toBe($group->id)
        ->and($stored->sales_rep_membership_id)->toBe($seller->id)
        ->and(Fixtures::audits($this->tenant, 'buyer_company.created'))->toHaveCount(1);
});

it('valida o formulário e exibe as regras de negócio nos campos', function (): void {
    $existing = $this->tenant->addBuyerCompany();

    Fixtures::livewireAs($this->tenant, $this->manager, static function () use ($existing): void {
        Livewire::test(BuyersIndex::class)
            ->call('openForm')
            ->call('save')
            ->assertHasErrors(['form.legalName' => 'required', 'form.tradeName' => 'required', 'form.document' => 'required'])
            ->set('form.legalName', 'Construtora Horizonte Verde Ltda')
            ->set('form.tradeName', 'Horizonte Verde')
            ->set('form.document', '11.111.111/1111-11')
            ->set('form.email', 'compras@')
            ->call('save')
            ->assertHasErrors(['form.email' => 'email'])
            ->set('form.email', '')
            ->call('save')
            ->assertHasErrors(['form.document' => 'CNPJ inválido.'])
            ->set('form.document', $existing->document)
            ->call('save')
            ->assertHasErrors(['form.document' => 'Já existe uma empresa compradora cadastrada com este CNPJ.'])
            ->assertNoRedirect();
    });

    expect(($this->companies)())->toHaveCount(1);
});

it('refaz a autorização ao cadastrar, independentemente da tela', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);

    Fixtures::livewireAs($this->tenant, $seller, static fn () => Livewire::test(BuyersIndex::class)
        ->set('creating', true)
        ->set('form.legalName', 'Construtora Horizonte Verde Ltda')
        ->set('form.tradeName', 'Horizonte Verde')
        ->set('form.document', BrazilianDocuments::cnpj())
        ->call('save')
        ->assertForbidden());

    expect(($this->companies)())->toBe([]);
});

it('trata grupo de outro distribuidor escolhido na tela como inexistente', function (): void {
    $foreignGroup = Fixtures::group(TenantScenario::create(), 'REVENDAS', 'Revendas');

    Fixtures::livewireAs($this->tenant, $this->manager, static fn () => Livewire::test(BuyersIndex::class)
        ->call('openForm')
        ->set('form.legalName', 'Construtora Horizonte Verde Ltda')
        ->set('form.tradeName', 'Horizonte Verde')
        ->set('form.document', BrazilianDocuments::cnpj())
        ->set('form.customerGroupId', $foreignGroup->id)
        ->call('save')
        ->assertNotFound());

    expect(($this->companies)())->toBe([]);
});

it('busca pelo CNPJ alfanumérico, com ou sem pontuação e em minúsculas', function (): void {
    $this->tenant->addBuyerCompany(['trade_name' => 'Construtora Serra Azul']);
    $this->tenant->addBuyerCompany(['trade_name' => 'Distribuidora Nova', 'document' => '12ABC34501DE35']);

    Fixtures::livewireAs($this->tenant, $this->manager, static function (): void {
        Livewire::test(BuyersIndex::class)
            ->set('search', '12.ABC.345/01DE-35')
            ->assertSee('Distribuidora Nova')
            ->assertSee('12.ABC.345/01DE-35')
            ->assertDontSee('Construtora Serra Azul')
            ->set('search', '12abc345')
            ->assertSee('Distribuidora Nova')
            ->assertDontSee('Construtora Serra Azul');
    });
});
