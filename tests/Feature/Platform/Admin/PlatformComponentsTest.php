<?php

declare(strict_types=1);

use App\Enums\Identity\OrganizationStatus;
use App\Livewire\Platform\OrganizationsIndex;
use App\Livewire\Platform\PlansIndex;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use App\Models\Platform\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    (new PlanSeeder)->run();

    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create()->fresh();
});

describe('planos', function (): void {
    it('carrega o plano com valores em reais e grava a alteração em centavos', function (): void {
        $plan = Plan::query()->where('code', 'profissional')->sole();

        Livewire::actingAs($this->admin)
            ->test(PlansIndex::class)
            ->call('edit', $plan->id)
            ->assertSet('editingId', $plan->id)
            ->assertSet('monthlyPrice', '2490,00')
            ->assertSet('setupFee', '4500,00')
            ->assertSet('maxStaffUsers', '20')
            ->assertSet('maxBranches', '5')
            ->assertSet('maxOrdersPerMonth', '3000')
            ->assertSet('modules', ['quotes', 'approvals', 'returns', 'imports'])
            ->set('monthlyPrice', '2690,5')
            ->set('setupFee', '0,29')
            ->set('maxBranches', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('message', 'Plano salvo.')
            ->assertSet('editingId', null);

        $stored = Plan::query()->findOrFail($plan->id);

        expect($stored->monthly_price_cents)->toBe(269_050)
            ->and($stored->setup_fee_cents)->toBe(29)
            ->and($stored->max_branches)->toBeNull()
            ->and($stored->max_staff_users)->toBe(20)
            ->and($stored->code)->toBe('profissional')
            ->and(DB::table('platform_audit_logs')->where('action', 'plan.updated')->count())->toBe(1);
    });

    it('cria novo plano pela tela com os módulos selecionados', function (): void {
        Livewire::actingAs($this->admin)
            ->test(PlansIndex::class)
            ->set('code', 'atacado-regional')
            ->set('name', 'Atacado Regional')
            ->set('monthlyPrice', '1590')
            ->set('setupFee', '3000,00')
            ->set('maxStaffUsers', '12')
            ->set('maxBranches', '3')
            ->set('maxOrdersPerMonth', '')
            ->set('trialDays', 7)
            ->set('modules', ['quotes', 'returns'])
            ->set('isPublic', false)
            ->call('save')
            ->assertHasNoErrors();

        $plan = Plan::query()->where('code', 'atacado-regional')->sole();

        expect($plan->monthly_price_cents)->toBe(159_000)
            ->and($plan->setup_fee_cents)->toBe(300_000)
            ->and($plan->max_orders_per_month)->toBeNull()
            ->and($plan->modules)->toBe(['quotes', 'returns'])
            ->and($plan->trial_days)->toBe(7)
            ->and($plan->is_public)->toBeFalse()
            ->and($plan->is_active)->toBeTrue()
            ->and(DB::table('platform_audit_logs')->where('action', 'plan.created')->count())->toBe(1);
    });

    it('valida formato monetário brasileiro, código único, limites, prazo de avaliação e módulos', function (): void {
        Livewire::actingAs($this->admin)
            ->test(PlansIndex::class)
            ->set('code', 'profissional')
            ->set('name', 'Profissional Duplicado')
            ->set('monthlyPrice', '2690.50')
            ->set('setupFee', '1.500,00')
            ->set('maxStaffUsers', '0')
            ->set('trialDays', 120)
            ->set('modules', ['quotes', 'nota-fiscal'])
            ->call('save')
            ->assertHasErrors([
                'code' => 'unique',
                'monthlyPrice' => 'regex',
                'setupFee' => 'regex',
                'maxStaffUsers' => 'min',
                'trialDays' => 'max',
                'modules.1',
            ])
            ->assertSet('message', null);

        expect(Plan::query()->count())->toBe(3)
            ->and(Plan::query()->where('code', 'profissional')->value('name'))->toBe('Profissional')
            ->and(DB::table('platform_audit_logs')->count())->toBe(0);
    });

    it('recusa a gravação de plano por usuário que não é administrador da plataforma', function (): void {
        $plan = Plan::query()->where('code', 'essencial')->sole();

        Livewire::actingAs(User::factory()->withTwoFactor()->create()->fresh())
            ->test(PlansIndex::class)
            ->call('edit', $plan->id)
            ->set('monthlyPrice', '1,00')
            ->call('save')
            ->assertForbidden();

        expect(Plan::query()->findOrFail($plan->id)->monthly_price_cents)->toBe(89_000)
            ->and(DB::table('platform_audit_logs')->count())->toBe(0);
    });
});

describe('organizações', function (): void {
    it('suspende e reativa a organização com justificativa pela tela da plataforma', function (): void {
        $tenant = TenantScenario::create('profissional', 'Comercial Santa Fé');
        $id = $tenant->organization->id;

        $component = Livewire::actingAs($this->admin)
            ->test(OrganizationsIndex::class)
            ->assertSee('Comercial Santa Fé')
            ->assertSee('Suspender')
            ->call('suspend', $id)
            ->assertHasErrors(['reason']);

        expect($component->errors()->first('reason'))->toBe('Informe a justificativa (mínimo de 5 caracteres).')
            ->and(Organization::query()->findOrFail($id)->status)->toBe(OrganizationStatus::Trial);

        $component->set('reason', 'Inadimplência confirmada pelo financeiro')
            ->call('suspend', $id)
            ->assertSet('message', 'Situação atualizada.')
            ->assertSet('reason', '')
            ->assertSee('Suspensa')
            ->assertSee('Reativar');

        expect(Organization::query()->findOrFail($id)->status)->toBe(OrganizationStatus::Suspended);

        $component->set('reason', 'Pagamento regularizado')
            ->call('reactivate', $id)
            ->assertSet('message', 'Situação atualizada.');

        expect(Organization::query()->findOrFail($id)->status)->toBe(OrganizationStatus::Active)
            ->and(DB::table('platform_audit_logs')->where('action', 'organization.status_changed')->pluck('reason')->sort()->values()->all())
            ->toBe(['Inadimplência confirmada pelo financeiro', 'Pagamento regularizado']);
    });

    it('remove o aviso de justificativa ausente depois que a suspensão é concluída', function (): void {
        $tenant = TenantScenario::create();

        Livewire::actingAs($this->admin)
            ->test(OrganizationsIndex::class)
            ->call('suspend', $tenant->organization->id)
            ->assertHasErrors(['reason'])
            ->set('reason', 'Uso indevido da plataforma')
            ->call('suspend', $tenant->organization->id)
            ->assertSet('message', 'Situação atualizada.')
            ->assertHasNoErrors()
            ->assertDontSee('Informe a justificativa (mínimo de 5 caracteres).');
    });

    it('recusa a suspensão por usuário que não é administrador da plataforma', function (): void {
        $tenant = TenantScenario::create();

        Livewire::actingAs($tenant->owner->fresh())
            ->test(OrganizationsIndex::class)
            ->set('reason', 'Tentativa sem permissão')
            ->call('suspend', $tenant->organization->id)
            ->assertForbidden();

        expect(Organization::query()->findOrFail($tenant->organization->id)->status)->toBe(OrganizationStatus::Trial)
            ->and(DB::table('platform_audit_logs')->where('action', 'organization.status_changed')->count())->toBe(0);
    });
});
