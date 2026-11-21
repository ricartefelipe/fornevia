<?php

declare(strict_types=1);

use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\TenantScenario;

/*
| Distribuidores criados antes da permissão de regras e delegações de
| aprovação: a migration de dados acrescenta portal.approval_rules ao
| administrador da empresa compradora de cada tenant, uma única vez, e a
| retira no rollback. Os demais papéis não mudam.
*/

beforeEach(function (): void {
    $this->tenants = [TenantScenario::create(name: 'Distribuidora Serra Azul'), TenantScenario::create(name: 'Distribuidora Vale Verde')];
    $this->migration = require database_path('migrations/2026_11_21_000300_grant_approval_rules_to_buyer_admins.php');
    $this->permissions = fn (TenantScenario $tenant, SystemRole $role): array => $tenant->system(
        fn (): array => json_decode((string) DB::table('roles')->where('key', $role->value)->value('permissions'), true),
    );
    $this->revoke = function (TenantScenario $tenant): void {
        $tenant->system(fn () => DB::update(
            "update roles set permissions = permissions - ? where key = 'buyer_admin'",
            [Permission::PortalApprovalRules->value],
        ));
    };
});

it('acrescenta a permissão ao administrador da empresa compradora de cada tenant', function (): void {
    foreach ($this->tenants as $tenant) {
        ($this->revoke)($tenant);
    }

    $purchaser = ($this->permissions)($this->tenants[0], SystemRole::Purchaser);

    $this->migration->up();
    $this->migration->up();

    foreach ($this->tenants as $tenant) {
        $granted = ($this->permissions)($tenant, SystemRole::BuyerAdmin);

        expect($granted)->toContain(Permission::PortalApprovalRules->value)
            ->and(array_count_values($granted)[Permission::PortalApprovalRules->value])->toBe(1);
    }

    expect(($this->permissions)($this->tenants[0], SystemRole::Purchaser))->toBe($purchaser);
});

it('retira a permissão no rollback', function (): void {
    $this->migration->down();

    foreach ($this->tenants as $tenant) {
        expect(($this->permissions)($tenant, SystemRole::BuyerAdmin))->not->toContain(Permission::PortalApprovalRules->value);
    }
});
