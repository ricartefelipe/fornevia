<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\BranchesIndex;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Database\Factories\Support\BrazilianDocuments;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->asMember = fn (TenantScenario $tenant, Membership $membership, Closure $callback): mixed => $tenant->actingAs(
        $membership,
        static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        },
    );

    $this->branches = fn (TenantScenario $tenant): array => $tenant->system(
        static fn (): array => Branch::query()->orderBy('code')->get()
            ->mapWithKeys(static fn (Branch $b): array => [$b->code => ['name' => $b->name, 'is_active' => $b->is_active]])
            ->all(),
    );

    $this->headquartersId = fn (TenantScenario $tenant): string => $tenant->system(
        static fn (): string => Branch::query()->where('is_headquarters', true)->value('id'),
    );
});

it('nega a página de filiais a quem não tem organization.manage', function (SystemRole $role): void {
    $tenant = TenantScenario::create();
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $tenant->addStaff($role, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('branches.index'))
        ->assertForbidden();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'estoque e expedição' => [SystemRole::Warehouse],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('nega a página de filiais no contexto de empresa compradora', function (): void {
    $tenant = TenantScenario::create();
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $tenant->addBuyerUser($tenant->addBuyerCompany(), SystemRole::BuyerAdmin, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('branches.index'))
        ->assertForbidden();
});

it('exibe a página de filiais ao administrador com segundo fator', function (): void {
    $tenant = TenantScenario::create();
    $user = User::factory()->withTwoFactor()->create()->fresh();
    $membership = $tenant->addStaff(SystemRole::Admin, $user);

    $this->actingAs($user)
        ->withSession(TenantScenario::sessionFor($membership))
        ->get(route('branches.index'))
        ->assertOk()
        ->assertSee('MATRIZ');
});

it('cadastra filial pela tela com código e UF normalizados', function (): void {
    $tenant = TenantScenario::create('profissional');
    $document = BrazilianDocuments::cnpj();
    $formatted = vsprintf('%s.%s.%s/%s-%s', [
        substr($document, 0, 2), substr($document, 2, 3), substr($document, 5, 3), substr($document, 8, 4), substr($document, 12, 2),
    ]);

    ($this->asMember)($tenant, $tenant->ownerMembership, static fn () => Livewire::test(BranchesIndex::class)
        ->set('code', 'jdi-01')
        ->set('name', 'Filial Jundiaí')
        ->set('document', $formatted)
        ->set('city', 'Jundiaí')
        ->set('state', 'sp')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Filial cadastrada.')
        ->assertSet('editingId', null)
        ->assertSet('code', ''));

    $stored = $tenant->system(static fn (): Branch => Branch::query()->where('code', 'JDI-01')->firstOrFail());

    expect($stored->name)->toBe('Filial Jundiaí')
        ->and($stored->document)->toBe($document)
        ->and($stored->state)->toBe('SP')
        ->and($stored->is_active)->toBeTrue()
        ->and($tenant->system(static fn (): int => AuditLog::query()->where('action', 'branch.created')->count()))->toBe(1);
});

it('exibe o limite de filiais do plano sem cadastrar', function (): void {
    $tenant = TenantScenario::create('essencial');

    ($this->asMember)($tenant, $tenant->ownerMembership, static fn () => Livewire::test(BranchesIndex::class)
        ->set('code', 'SJC01')
        ->set('name', 'Filial São José dos Campos')
        ->call('save')
        ->assertHasErrors(['code' => 'O plano Essencial permite até 1 filiais.'])
        ->assertSet('message', null));

    expect(array_keys(($this->branches)($tenant)))->toBe(['MATRIZ']);
});

it('mantém a matriz ativa mesmo quando o formulário pede a inativação', function (): void {
    $tenant = TenantScenario::create();
    $headquartersId = ($this->headquartersId)($tenant);

    ($this->asMember)($tenant, $tenant->ownerMembership, static fn () => Livewire::test(BranchesIndex::class)
        ->call('edit', $headquartersId)
        ->assertSet('editingId', $headquartersId)
        ->set('name', 'Matriz Osasco')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('message', 'Filial atualizada.'));

    expect(($this->branches)($tenant))->toBe(['MATRIZ' => ['name' => 'Matriz Osasco', 'is_active' => true]]);
});

it('refaz a autorização ao salvar, independentemente da rota', function (): void {
    $tenant = TenantScenario::create();
    $manager = $tenant->addStaff(SystemRole::SalesManager);

    ($this->asMember)($tenant, $manager, static fn () => Livewire::test(BranchesIndex::class)
        ->set('code', 'RIB01')
        ->set('name', 'Filial Ribeirão Preto')
        ->call('save')
        ->assertForbidden());

    expect(array_keys(($this->branches)($tenant)))->toBe(['MATRIZ']);
});

it('protege editingId contra alteração pelo cliente', function (): void {
    $tenant = TenantScenario::create();
    $headquartersId = ($this->headquartersId)($tenant);
    $branch = $tenant->system(static fn (): Branch => Branch::factory()->create(['code' => 'CTB01', 'name' => 'Filial Curitiba']));

    ($this->asMember)($tenant, $tenant->ownerMembership, static function () use ($branch, $headquartersId): void {
        $component = Livewire::test(BranchesIndex::class)
            ->call('edit', $branch->id)
            ->assertSet('editingId', $branch->id);

        expect(fn () => $component->set('editingId', $headquartersId))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        expect(fn () => Livewire::test(BranchesIndex::class)->set('editingId', $headquartersId))
            ->toThrow(CannotUpdateLockedPropertyException::class);

        // O estado aceito pelo servidor continua apontando para a filial em edição.
        $component->set('name', 'Filial Curitiba Centro')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Filial atualizada.');
    });

    expect(($this->branches)($tenant))->toBe([
        'CTB01' => ['name' => 'Filial Curitiba Centro', 'is_active' => true],
        'MATRIZ' => ['name' => 'Matriz', 'is_active' => true],
    ]);
});

it('trata como inexistente a filial de outro tenant', function (): void {
    $tenant = TenantScenario::create();
    $other = TenantScenario::create();
    $foreignHeadquartersId = ($this->headquartersId)($other);

    ($this->asMember)($tenant, $tenant->ownerMembership, static fn () => Livewire::test(BranchesIndex::class)
        ->call('edit', $foreignHeadquartersId)
        ->assertNotFound());

    expect(($this->branches)($other))->toBe(['MATRIZ' => ['name' => 'Matriz', 'is_active' => true]]);
});

it('trata identificador de filial malformado como inexistente', function (): void {
    $tenant = TenantScenario::create();

    ($this->asMember)($tenant, $tenant->ownerMembership, static fn () => Livewire::test(BranchesIndex::class)
        ->call('edit', 'MATRIZ')
        ->assertNotFound());

    expect(($this->branches)($tenant))->toBe(['MATRIZ' => ['name' => 'Matriz', 'is_active' => true]]);
});
