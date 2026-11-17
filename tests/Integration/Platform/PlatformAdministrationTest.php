<?php

declare(strict_types=1);

use App\Actions\Identity\AcceptInvitation;
use App\Actions\Identity\CreateOrganization;
use App\Actions\Identity\SelectContext;
use App\Actions\Platform\ChangeOrganizationStatus;
use App\Actions\Platform\SavePlan;
use App\Data\Identity\CreateOrganizationData;
use App\Domain\Identity\Exceptions\ContextUnavailable;
use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Domain\Platform\Exceptions\PlanUnavailable;
use App\Domain\Shared\Cnpj;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Invitation;
use App\Models\Identity\Organization;
use App\Models\Identity\User;
use App\Models\Platform\Plan;
use App\Services\Identity\ContextDirectory;
use App\Services\Platform\PlanLimits;
use Database\Factories\Support\BrazilianDocuments;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/**
 * Atributos completos de um plano, como enviados pela tela de planos.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function administracaoAtributosPlano(array $overrides = []): array
{
    return [
        'code' => 'atacado-regional',
        'name' => 'Atacado Regional',
        'description' => 'Distribuidores regionais com equipe enxuta.',
        'monthly_price_cents' => 159_090,
        'setup_fee_cents' => 300_000,
        'max_staff_users' => 12,
        'max_branches' => 3,
        'max_orders_per_month' => 1_500,
        'modules' => ['quotes'],
        'trial_days' => 7,
        'is_public' => true,
        'is_active' => true,
        'sort_order' => 15,
        ...$overrides,
    ];
}

/**
 * @return array<string, mixed>
 */
function administracaoAtributosDe(Plan $plan): array
{
    return $plan->only([
        'code', 'name', 'description', 'monthly_price_cents', 'setup_fee_cents', 'max_staff_users', 'max_branches',
        'max_orders_per_month', 'modules', 'trial_days', 'is_public', 'is_active', 'sort_order',
    ]);
}

/**
 * @return list<object>
 */
function administracaoAuditoria(string $action): array
{
    return DB::table('platform_audit_logs')->where('action', $action)->orderBy('created_at')->get()->all();
}

beforeEach(function (): void {
    (new PlanSeeder)->run();

    $this->admin = User::factory()->platformAdmin()->withTwoFactor()->create();
});

describe('planos', function (): void {
    it('recusa a gestão de planos por quem não é administrador da plataforma', function (): void {
        $tenant = TenantScenario::create('profissional');
        $profissional = Plan::query()->where('code', 'profissional')->sole();

        foreach ([User::factory()->withTwoFactor()->create(), $tenant->owner] as $actor) {
            expect(fn () => app(SavePlan::class)->handle($actor, null, administracaoAtributosPlano()))
                ->toThrow(AuthorizationException::class, 'Somente administradores da plataforma gerenciam planos.');

            expect(fn () => app(SavePlan::class)->handle($actor, $profissional, [...administracaoAtributosDe($profissional), 'monthly_price_cents' => 1]))
                ->toThrow(AuthorizationException::class);
        }

        expect(Plan::query()->count())->toBe(3)
            ->and(Plan::query()->where('code', 'profissional')->value('monthly_price_cents'))->toBe(249_000)
            ->and(administracaoAuditoria('plan.created'))->toBe([])
            ->and(administracaoAuditoria('plan.updated'))->toBe([]);
    });

    it('cria plano descartando módulos desconhecidos ou repetidos e registra auditoria', function (): void {
        Context::add('correlation_id', 'plataforma-planos-01');

        $plan = app(SavePlan::class)->handle($this->admin, null, administracaoAtributosPlano([
            'modules' => ['quotes', 'api', 'quotes', 'nota-fiscal'],
        ]));

        $stored = Plan::query()->findOrFail($plan->id);

        expect($stored->code)->toBe('atacado-regional')
            ->and($stored->monthly_price_cents)->toBe(159_090)
            ->and($stored->max_staff_users)->toBe(12)
            ->and($stored->modules)->toBe(['quotes', 'api'])
            ->and($stored->trial_days)->toBe(7);

        $audit = administracaoAuditoria('plan.created');

        expect($audit)->toHaveCount(1)
            ->and($audit[0]->actor_user_id)->toBe($this->admin->id)
            ->and($audit[0]->organization_id)->toBeNull()
            ->and($audit[0]->correlation_id)->toBe('plataforma-planos-01')
            ->and(json_decode((string) $audit[0]->data, true))->toEqual([
                'plan' => 'atacado-regional',
                'before' => null,
                'after' => [
                    'monthly_price_cents' => 159_090,
                    'setup_fee_cents' => 300_000,
                    'max_staff_users' => 12,
                    'max_branches' => 3,
                    'max_orders_per_month' => 1_500,
                    'modules' => ['quotes', 'api'],
                ],
            ]);
    });

    it('altera plano existente registrando os valores anteriores e os novos', function (): void {
        $plan = Plan::query()->where('code', 'profissional')->sole();

        app(SavePlan::class)->handle($this->admin, $plan, [
            ...administracaoAtributosDe($plan),
            'monthly_price_cents' => 269_000,
            'max_branches' => null,
            'modules' => [...$plan->modules, 'api'],
        ]);

        $stored = Plan::query()->findOrFail($plan->id);
        $audit = administracaoAuditoria('plan.updated');

        expect($stored->monthly_price_cents)->toBe(269_000)
            ->and($stored->max_branches)->toBeNull()
            ->and($stored->modules)->toBe(['quotes', 'approvals', 'returns', 'imports', 'api'])
            ->and(Plan::query()->count())->toBe(3)
            ->and($audit)->toHaveCount(1);

        $data = json_decode((string) $audit[0]->data, true);

        expect($data['plan'])->toBe('profissional')
            ->and($data['before'])->toEqual([
                'monthly_price_cents' => 249_000, 'setup_fee_cents' => 450_000, 'max_staff_users' => 20,
                'max_branches' => 5, 'max_orders_per_month' => 3_000, 'modules' => ['quotes', 'approvals', 'returns', 'imports'],
            ])
            ->and($data['after'])->toEqual([
                'monthly_price_cents' => 269_000, 'setup_fee_cents' => 450_000, 'max_staff_users' => 20,
                'max_branches' => null, 'max_orders_per_month' => 3_000, 'modules' => ['quotes', 'approvals', 'returns', 'imports', 'api'],
            ]);
    });

    it('aplica os limites alterados às verificações seguintes e às novas contratações', function (): void {
        $tenant = TenantScenario::create('profissional');
        $plan = Plan::query()->where('code', 'profissional')->sole();

        $tenant->system(fn () => app(PlanLimits::class)->assertCanAddBranch());

        app(SavePlan::class)->handle($this->admin, $plan, [...administracaoAtributosDe($plan), 'max_branches' => 1, 'is_public' => false]);

        expect(fn () => $tenant->system(fn () => app(PlanLimits::class)->assertCanAddBranch()))
            ->toThrow(function (PlanLimitReached $e): void {
                expect($e->details())->toBe(['limit' => 1, 'resource' => 'branches']);
            });

        expect(fn () => app(CreateOrganization::class)->handle(User::factory()->create(), new CreateOrganizationData(
            legalName: 'Serra Azul Embalagens Ltda',
            tradeName: 'Serra Azul Embalagens',
            document: Cnpj::fromString(BrazilianDocuments::cnpj()),
            planCode: 'profissional',
        )))->toThrow(PlanUnavailable::class);
    });
});

describe('situação das organizações', function (): void {
    it('recusa a alteração por quem não é administrador da plataforma, inclusive o proprietário', function (): void {
        $tenant = TenantScenario::create();

        foreach ([$tenant->owner, User::factory()->withTwoFactor()->create()] as $actor) {
            expect(fn () => app(ChangeOrganizationStatus::class)->handle($actor, $tenant->organization, OrganizationStatus::Active, 'Pagamento confirmado'))
                ->toThrow(AuthorizationException::class, 'Somente administradores da plataforma alteram a situação de organizações.');
        }

        expect(Organization::query()->findOrFail($tenant->organization->id)->status)->toBe(OrganizationStatus::Trial)
            ->and(administracaoAuditoria('organization.status_changed'))->toBe([]);
    });

    it('exige justificativa com ao menos cinco caracteres', function (string $reason): void {
        $tenant = TenantScenario::create();

        expect(fn () => app(ChangeOrganizationStatus::class)->handle($this->admin, $tenant->organization, OrganizationStatus::Suspended, $reason))
            ->toThrow(InvalidArgumentException::class, 'Informe a justificativa.');

        expect(Organization::query()->findOrFail($tenant->organization->id)->status)->toBe(OrganizationStatus::Trial)
            ->and(administracaoAuditoria('organization.status_changed'))->toBe([]);
    })->with([
        'vazia' => [''],
        'somente espaços' => ['      '],
        'curta com espaços ao redor' => ['  abc  '],
        'quatro caracteres acentuados (seis bytes)' => ['Ação'],
    ]);

    it('aceita justificativa de exatamente cinco caracteres, armazenada sem espaços nas bordas', function (): void {
        $tenant = TenantScenario::create();

        app(ChangeOrganizationStatus::class)->handle($this->admin, $tenant->organization, OrganizationStatus::Suspended, '  Abuso  ');
        app(ChangeOrganizationStatus::class)->handle($this->admin, $tenant->organization, OrganizationStatus::Active, 'Ações');

        expect(array_map(static fn (object $row): ?string => $row->reason, administracaoAuditoria('organization.status_changed')))
            ->toEqualCanonicalizing(['Abuso', 'Ações']);
    });

    it('suspende a organização registrando situação anterior, nova situação e justificativa', function (): void {
        $tenant = TenantScenario::create();
        Context::add('correlation_id', 'plataforma-org-77');

        $result = app(ChangeOrganizationStatus::class)->handle(
            $this->admin,
            $tenant->organization,
            OrganizationStatus::Suspended,
            '  Inadimplência recorrente há 60 dias  ',
        );

        expect($result->status)->toBe(OrganizationStatus::Suspended)
            ->and(Organization::query()->findOrFail($tenant->organization->id)->status)->toBe(OrganizationStatus::Suspended);

        $audit = administracaoAuditoria('organization.status_changed');

        expect($audit)->toHaveCount(1)
            ->and($audit[0]->actor_user_id)->toBe($this->admin->id)
            ->and($audit[0]->organization_id)->toBe($tenant->organization->id)
            ->and($audit[0]->reason)->toBe('Inadimplência recorrente há 60 dias')
            ->and($audit[0]->correlation_id)->toBe('plataforma-org-77')
            ->and(json_decode((string) $audit[0]->data, true))->toEqual(['from' => 'trial', 'to' => 'suspended']);
    });

    it('bloqueia a seleção de contexto e o aceite de convites enquanto a organização estiver suspensa', function (): void {
        $tenant = TenantScenario::create();
        $rep = $tenant->addStaff(SystemRole::SalesRep);
        $company = $tenant->addBuyerCompany();
        $buyer = $tenant->addBuyerUser($company);
        $invited = User::factory()->create();
        $token = Str::random(64);

        $tenant->system(function () use ($tenant, $invited, $token): void {
            $invitation = new Invitation;
            $invitation->forceFill([
                'email' => $invited->email,
                'kind' => MembershipKind::Staff,
                'role_id' => $tenant->role(SystemRole::Viewer)->id,
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(7),
            ])->save();
        });

        app(ChangeOrganizationStatus::class)->handle($this->admin, $tenant->organization, OrganizationStatus::Suspended, 'Solicitação do jurídico');

        foreach ([$tenant->ownerMembership, $rep, $buyer] as $membership) {
            expect(fn () => app(SelectContext::class)->handle(User::query()->findOrFail($membership->user_id), $membership->id))
                ->toThrow(ContextUnavailable::class);
        }

        $options = app(ContextDirectory::class)->forUser($tenant->owner);

        expect($options)->toHaveCount(1)
            ->and($options[0]->available)->toBeFalse();

        expect(fn () => app(AcceptInvitation::class)->handle($tenant->organization->id, $token, $invited))
            ->toThrow(ContextUnavailable::class);

        $this->travel(5)->minutes();
        app(ChangeOrganizationStatus::class)->handle($this->admin, $tenant->organization, OrganizationStatus::Active, 'Regularização concluída');

        $selected = app(SelectContext::class)->handle(User::query()->findOrFail($rep->user_id), $rep->id);
        $accepted = app(AcceptInvitation::class)->handle($tenant->organization->id, $token, $invited);

        expect($selected->id)->toBe($rep->id)
            ->and($accepted->user_id)->toBe($invited->id)
            ->and(app(ContextDirectory::class)->forUser($tenant->owner)[0]->available)->toBeTrue();

        expect(array_map(
            static fn (object $row): array => json_decode((string) $row->data, true),
            administracaoAuditoria('organization.status_changed'),
        ))->toEqual([
            ['from' => 'trial', 'to' => 'suspended'],
            ['from' => 'suspended', 'to' => 'active'],
        ]);
    });
});
