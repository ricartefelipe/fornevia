<?php

declare(strict_types=1);

use App\Actions\Buyers\ChangeBuyerCompanyStatus;
use App\Domain\Shared\Cnpj;
use App\Enums\Buyers\BuyerCompanyStatus;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Buyers\BuyerShow;
use App\Models\Buyers\BuyerAddress;
use App\Models\Buyers\BuyerContact;
use App\Models\Identity\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Buyers\BuyerFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = TenantScenario::create();
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);
    $this->company = $this->tenant->addBuyerCompany([
        'trade_name' => 'Construtora Serra Azul',
        'legal_name' => 'Serra Azul Engenharia Ltda',
    ]);
});

it('nega a página da empresa a quem não tem buyers.view', function (): void {
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::Warehouse);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.show', ['buyer' => $this->company->id]))
        ->assertForbidden();
});

it('nega a página da empresa no contexto de comprador, inclusive da própria empresa', function (): void {
    $user = User::factory()->create()->fresh();
    $membership = $this->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.show', ['buyer' => $this->company->id]))
        ->assertForbidden();
});

it('responde 404 para empresa de outro distribuidor e para identificador malformado', function (): void {
    $foreign = TenantScenario::create()->addBuyerCompany(['trade_name' => 'Empresa de outro distribuidor']);
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::SalesManager);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.show', ['buyer' => $foreign->id]))
        ->assertNotFound()
        ->assertDontSee('Empresa de outro distribuidor');

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get('/empresas/'.rawurlencode("{$this->company->id}' or '1'='1"))
        ->assertNotFound();
});

it('exibe dados, situação, seções e histórico ao representante, sem ações de gestão', function (): void {
    $group = Fixtures::group($this->tenant, 'CONSTRUTORAS', 'Construtoras');
    $rep = $this->tenant->addStaff(SystemRole::SalesRep, User::factory()->create(['name' => 'Rafael Antunes Moreira']));
    $this->tenant->system(function () use ($group, $rep): void {
        $this->company->newQuery()->findOrFail($this->company->id)->forceFill([
            'customer_group_id' => $group->id,
            'sales_rep_membership_id' => $rep->id,
        ])->save();

        $contact = new BuyerContact;
        $contact->forceFill(['buyer_company_id' => $this->company->id, 'name' => 'Patrícia Nogueira', 'is_primary' => true])->save();

        $address = new BuyerAddress;
        $address->forceFill([
            'buyer_company_id' => $this->company->id, 'kind' => 'shipping', 'label' => 'Obra Vila Mariana',
            'street' => 'Rua Domingos de Morais', 'number' => '1200', 'district' => 'Vila Mariana',
            'city' => 'São Paulo', 'state' => 'SP', 'postal_code' => '04010100', 'is_default' => true,
        ])->save();
    });
    Fixtures::unit($this->tenant, $this->company, 'OBRA-VM', 'Unidade Vila Mariana');
    $this->tenant->actingAs($this->manager, fn () => app(ChangeBuyerCompanyStatus::class)
        ->handle($this->company, BuyerCompanyStatus::Blocked, 'Inadimplência acima de 60 dias'));

    // O próprio representante da empresa consulta (a empresa está na carteira dele).
    [$user, $membership] = [User::query()->findOrFail($rep->user_id), $rep];

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('buyers.show', ['buyer' => $this->company->id]))
        ->assertOk()
        ->assertSee('Construtora Serra Azul')
        ->assertSee(Cnpj::format($this->company->document))
        ->assertSee('Bloqueada')
        ->assertSee('Inadimplência acima de 60 dias')
        ->assertSee('Construtoras (CONSTRUTORAS)')
        ->assertSee('Rafael Antunes Moreira')
        ->assertSee('Patrícia Nogueira')
        ->assertSee('Obra Vila Mariana')
        ->assertSee('CEP 04010-100')
        ->assertSee('Unidade Vila Mariana')
        ->assertSee('Centros de custo')
        ->assertSee('Condições de pagamento')
        ->assertSee('Usuários da empresa')
        ->assertSee('Histórico')
        ->assertSee('Situação alterada')
        ->assertDontSee('Editar dados')
        ->assertDontSee('Alterar situação')
        ->assertDontSee('Adicionar contato')
        ->assertDontSee('Enviar convite');
});

it('edita os dados cadastrais pela tela e incrementa a versão de preços ao trocar o grupo', function (): void {
    $group = Fixtures::group($this->tenant, 'REVENDAS', 'Revendas');
    $version = Fixtures::priceVersion($this->tenant);

    Fixtures::livewireAs($this->tenant, $this->manager, fn () => Livewire::test(BuyerShow::class, ['buyer' => $this->company])
        ->call('startEditing')
        ->assertSet('editing', true)
        ->assertSet('form.tradeName', 'Construtora Serra Azul')
        ->assertSet('form.document', Cnpj::format($this->company->document))
        ->set('form.tradeName', 'Serra Azul Construções')
        ->set('form.customerGroupId', $group->id)
        ->call('saveProfile')
        ->assertHasNoErrors()
        ->assertSet('editing', false)
        ->assertSet('message', 'Dados cadastrais atualizados.')
        ->assertDispatched('buyer-updated'));

    $stored = Fixtures::company($this->tenant, $this->company->id);

    expect($stored->trade_name)->toBe('Serra Azul Construções')
        ->and($stored->customer_group_id)->toBe($group->id)
        ->and(Fixtures::priceVersion($this->tenant))->toBe($version + 1);
});

it('não abre a edição para quem não gerencia empresas e refaz a autorização ao salvar', function (): void {
    $seller = $this->tenant->addStaff(SystemRole::SalesRep);
    Tests\Integration\Ordering\Staff\StaffOrderFixtures::assignRep($this->tenant, $this->company, $seller);

    Fixtures::livewireAs($this->tenant, $seller, fn () => Livewire::test(BuyerShow::class, ['buyer' => $this->company])
        ->call('startEditing')
        ->assertSet('editing', false)
        ->set('form.legalName', 'Serra Azul Engenharia Ltda')
        ->set('form.tradeName', 'Alterada sem permissão')
        ->set('form.document', $this->company->document)
        ->call('saveProfile')
        ->assertForbidden());

    expect(Fixtures::company($this->tenant, $this->company->id)->trade_name)->toBe('Construtora Serra Azul');
});

it('altera a situação com justificativa e exibe a justificativa vigente', function (): void {
    Fixtures::livewireAs($this->tenant, $this->manager, fn () => Livewire::test(BuyerShow::class, ['buyer' => $this->company])
        ->set('newStatus', 'blocked')
        ->set('statusReason', 'ok')
        ->call('changeStatus')
        ->assertHasErrors(['statusReason' => 'min'])
        ->set('newStatus', 'suspensa')
        ->set('statusReason', 'Inadimplência acima de 60 dias')
        ->call('changeStatus')
        ->assertHasErrors(['newStatus'])
        ->set('newStatus', 'blocked')
        ->call('changeStatus')
        ->assertHasNoErrors()
        ->assertSet('message', 'Situação alterada para "Bloqueada".')
        ->assertSet('statusReason', '')
        ->assertSee('Inadimplência acima de 60 dias')
        ->assertDispatched('buyer-updated'));

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Blocked)
        ->and(Fixtures::audits($this->tenant, 'buyer_company.status_changed')->sole()->reason)->toBe('Inadimplência acima de 60 dias');
});

it('exibe a recusa de situação inalterada no campo da tela', function (): void {
    Fixtures::livewireAs($this->tenant, $this->manager, fn () => Livewire::test(BuyerShow::class, ['buyer' => $this->company])
        ->set('newStatus', 'active')
        ->set('statusReason', 'Reativação solicitada pelo comercial')
        ->call('changeStatus')
        ->assertHasErrors(['newStatus' => 'A empresa já está com a situação "Ativa".'])
        ->assertSet('message', null));

    expect(Fixtures::audits($this->tenant, 'buyer_company.status_changed'))->toBeEmpty();
});

it('refaz a autorização ao alterar a situação', function (): void {
    $viewer = $this->tenant->addStaff(SystemRole::Viewer);

    Fixtures::livewireAs($this->tenant, $viewer, fn () => Livewire::test(BuyerShow::class, ['buyer' => $this->company])
        ->set('newStatus', 'blocked')
        ->set('statusReason', 'Tentativa sem permissão de gestão')
        ->call('changeStatus')
        ->assertForbidden());

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Active);
});

it('protege o identificador da empresa contra alteração pelo cliente', function (): void {
    $other = $this->tenant->addBuyerCompany(['trade_name' => 'Engenharia Litoral']);

    Fixtures::livewireAs($this->tenant, $this->manager, function () use ($other): void {
        $component = Livewire::test(BuyerShow::class, ['buyer' => $this->company]);

        expect(fn () => $component->set('buyerId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class);

        $component->set('newStatus', 'inactive')
            ->set('statusReason', 'Encerramento das obras na região')
            ->call('changeStatus')
            ->assertHasNoErrors();
    });

    expect(Fixtures::company($this->tenant, $this->company->id)->status)->toBe(BuyerCompanyStatus::Inactive)
        ->and(Fixtures::company($this->tenant, $other->id)->status)->toBe(BuyerCompanyStatus::Active);
});

it('reaplica as exigências da rota nas ações das seções pelo endpoint do Livewire', function (): void {
    [$user, $membership] = Fixtures::staffUser($this->tenant, SystemRole::SalesManager);
    $session = TenantScenario::sessionFor($membership);

    $page = $this->actingAs($user)->withSession($session)
        ->get(route('buyers.show', ['buyer' => $this->company->id]))
        ->assertOk();

    preg_match_all('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);
    $snapshot = collect($matches[1])
        ->map(static fn (string $raw): string => html_entity_decode($raw, ENT_QUOTES | ENT_HTML5))
        ->first(static fn (string $json): bool => str_contains((string) json_decode($json, true)['memo']['name'], 'buyer-contacts'));

    expect($snapshot)->toBeString();

    $call = fn (string $name) => $this->actingAs($user)->withSession($session)->json('POST', app(\Livewire\Mechanisms\HandleRequests\HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['formOpen' => true, 'name' => $name],
            'calls' => [['method' => 'save', 'params' => [], 'metadata' => []]],
        ]],
    ], ['X-Livewire' => '1', 'Accept' => '*/*']);

    $call('Patrícia Nogueira')->assertOk();

    expect($this->tenant->system(fn (): array => BuyerContact::query()->where('buyer_company_id', $this->company->id)->pluck('name')->all()))
        ->toBe(['Patrícia Nogueira']);

    // Sem buyers.view, a mesma ação é recusada pelo middleware persistente da rota.
    $this->tenant->system(static fn () => App\Models\Identity\Membership::query()->findOrFail($membership->id)
        ->forceFill(['role_id' => App\Models\Identity\Role::query()->where('key', SystemRole::Warehouse->value)->value('id')])
        ->save());

    $call('Rodrigo Albuquerque')->assertForbidden();

    expect($this->tenant->system(fn (): int => BuyerContact::query()->where('buyer_company_id', $this->company->id)->count()))->toBe(1);
});
