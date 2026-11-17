<?php

declare(strict_types=1);

use App\Actions\Identity\SaveBranch;
use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Enums\Identity\SystemRole;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    $this->save = fn (TenantScenario $tenant, Membership $actor, ?Branch $branch, array $attributes): Branch => $tenant->actingAs(
        $actor,
        static fn (): Branch => app(SaveBranch::class)->handle($branch, $attributes),
    );

    $this->headquarters = fn (TenantScenario $tenant): Branch => $tenant->system(
        static fn (): Branch => Branch::query()->where('is_headquarters', true)->firstOrFail(),
    );

    $this->branchLogs = fn (TenantScenario $tenant, string $action) => $tenant->system(
        static fn () => AuditLog::query()->where('action', $action)->orderBy('created_at')->get(),
    );
});

it('cadastra filial com dados normalizados e registra a auditoria', function (): void {
    $tenant = TenantScenario::create('profissional');
    $admin = $tenant->addStaff(SystemRole::Admin);

    $branch = ($this->save)($tenant, $admin, null, [
        'code' => '  cps-01 ',
        'name' => '  Filial Campinas  ',
        'city' => 'Campinas',
        'state' => 'sp',
    ]);

    $stored = $tenant->system(static fn (): Branch => Branch::query()->findOrFail($branch->id));

    expect($stored->code)->toBe('CPS-01')
        ->and($stored->name)->toBe('Filial Campinas')
        ->and($stored->state)->toBe('SP')
        ->and($stored->is_active)->toBeTrue()
        ->and($stored->is_headquarters)->toBeFalse();

    $logs = ($this->branchLogs)($tenant, 'branch.created');

    expect($logs)->toHaveCount(1)
        ->and($logs->first()->subject_type)->toBe('branch')
        ->and($logs->first()->subject_id)->toBe($branch->id)
        ->and($logs->first()->actor_membership_id)->toBe($admin->id)
        ->and($logs->first()->data)->toEqual([
            'before' => null,
            'after' => ['code' => 'CPS-01', 'name' => 'Filial Campinas', 'is_active' => true],
        ]);
});

it('exige a permissão organization.manage', function (SystemRole $role): void {
    $tenant = TenantScenario::create('profissional');
    $actor = $tenant->addStaff($role);
    $headquarters = ($this->headquarters)($tenant);

    expect(fn () => ($this->save)($tenant, $actor, null, ['code' => 'RIB01', 'name' => 'Filial Ribeirão Preto']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->save)($tenant, $actor, $headquarters, ['code' => 'MATRIZ', 'name' => 'Matriz alterada']))
        ->toThrow(AuthorizationException::class);

    expect($tenant->system(static fn (): array => Branch::query()->pluck('name')->all()))->toBe(['Matriz'])
        ->and(($this->branchLogs)($tenant, 'branch.created'))->toBeEmpty()
        ->and(($this->branchLogs)($tenant, 'branch.updated'))->toBeEmpty();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'financeiro' => [SystemRole::Finance],
    'consulta' => [SystemRole::Viewer],
]);

it('respeita o limite de filiais do plano e continua permitindo editar as existentes', function (): void {
    $tenant = TenantScenario::create('essencial');
    $owner = $tenant->ownerMembership;
    $headquarters = ($this->headquarters)($tenant);

    try {
        ($this->save)($tenant, $owner, null, ['code' => 'SJC01', 'name' => 'Filial São José dos Campos']);
        $this->fail('O limite de filiais do plano deveria ter sido aplicado.');
    } catch (PlanLimitReached $e) {
        expect($e->errorCode())->toBe('plan_limit_reached')
            ->and($e->details())->toBe(['limit' => 1, 'resource' => 'branches'])
            ->and($e->getMessage())->toContain('até 1 filiais');
    }

    expect($tenant->system(static fn (): int => Branch::query()->count()))->toBe(1)
        ->and(($this->branchLogs)($tenant, 'branch.created'))->toBeEmpty();

    $updated = ($this->save)($tenant, $owner, $headquarters, ['code' => 'MATRIZ', 'name' => 'Matriz São Paulo']);

    expect($updated->name)->toBe('Matriz São Paulo');
});

it('aplica o limite do plano contando a matriz e as filiais já cadastradas', function (): void {
    $tenant = TenantScenario::create('profissional');
    $owner = $tenant->ownerMembership;

    foreach (['BAU01', 'SOR01', 'PIR01', 'MAR01'] as $code) {
        ($this->save)($tenant, $owner, null, ['code' => $code, 'name' => "Filial {$code}"]);
    }

    expect(fn () => ($this->save)($tenant, $owner, null, ['code' => 'ARA01', 'name' => 'Filial Araraquara']))
        ->toThrow(PlanLimitReached::class);

    expect($tenant->system(static fn (): int => Branch::query()->count()))->toBe(5);
});

it('mantém a matriz sempre ativa e audita a alteração', function (): void {
    $tenant = TenantScenario::create('profissional');
    $owner = $tenant->ownerMembership;
    $headquarters = ($this->headquarters)($tenant);

    ($this->save)($tenant, $owner, $headquarters, [
        'code' => 'MATRIZ',
        'name' => 'Matriz Guarulhos',
        'is_active' => false,
    ]);

    $stored = ($this->headquarters)($tenant);

    expect($stored->is_active)->toBeTrue()
        ->and($stored->name)->toBe('Matriz Guarulhos');

    expect(($this->branchLogs)($tenant, 'branch.updated')->first()?->data)->toEqual([
        'before' => ['code' => 'MATRIZ', 'name' => 'Matriz', 'is_active' => true],
        'after' => ['code' => 'MATRIZ', 'name' => 'Matriz Guarulhos', 'is_active' => true],
    ]);
});

it('inativa filial comum registrando os estados anterior e posterior', function (): void {
    $tenant = TenantScenario::create('profissional');
    $owner = $tenant->ownerMembership;

    $branch = ($this->save)($tenant, $owner, null, ['code' => 'LON01', 'name' => 'Filial Londrina', 'state' => 'PR']);
    ($this->save)($tenant, $owner, $branch, ['code' => 'LON01', 'name' => 'Filial Londrina', 'state' => 'PR', 'is_active' => false]);

    expect($tenant->system(static fn (): bool => Branch::query()->findOrFail($branch->id)->is_active))->toBeFalse()
        ->and(($this->branchLogs)($tenant, 'branch.updated')->first()?->data)->toEqual([
            'before' => ['code' => 'LON01', 'name' => 'Filial Londrina', 'is_active' => true],
            'after' => ['code' => 'LON01', 'name' => 'Filial Londrina', 'is_active' => false],
        ]);
});

it('não altera filial de outro tenant', function (): void {
    $tenant = TenantScenario::create('profissional');
    $other = TenantScenario::create('profissional');
    $foreignHeadquarters = ($this->headquarters)($other);

    expect(fn () => ($this->save)($tenant, $tenant->ownerMembership, $foreignHeadquarters, [
        'code' => 'MATRIZ',
        'name' => 'Matriz alterada indevidamente',
    ]))->toThrow(ModelNotFoundException::class);

    expect(($this->headquarters)($other)->name)->toBe('Matriz')
        ->and(($this->headquarters)($tenant)->name)->toBe('Matriz')
        ->and(($this->branchLogs)($tenant, 'branch.updated'))->toBeEmpty();
});
