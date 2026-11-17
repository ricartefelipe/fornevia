<?php

declare(strict_types=1);

use App\Actions\Identity\CreateOrganization;
use App\Data\Identity\CreateOrganizationData;
use App\Domain\Platform\Exceptions\OrganizationAlreadyRegistered;
use App\Domain\Platform\Exceptions\PlanUnavailable;
use App\Domain\Shared\Cnpj;
use App\Domain\Shared\ConflictingState;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\OrganizationStatus;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Enums\Platform\SubscriptionStatus;
use App\Models\Audit\AuditLog;
use App\Models\Identity\Branch;
use App\Models\Identity\Membership;
use App\Models\Identity\Organization;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Models\Platform\Subscription;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\Exceptions\TenantContextConflict;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Carbon\CarbonImmutable;
use Database\Factories\Support\BrazilianDocuments;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

function onboardingDados(string $cnpj, string $plan = 'profissional', string $tradeName = 'Horizonte Atacadista'): CreateOrganizationData
{
    return new CreateOrganizationData(
        legalName: $tradeName.' Distribuição Ltda',
        tradeName: $tradeName,
        document: Cnpj::fromString($cnpj),
        planCode: $plan,
        email: 'comercial@horizonte-atacadista.example',
        phone: '1133224455',
    );
}

beforeEach(function (): void {
    (new PlanSeeder)->run();

    $this->clock = FrozenClock::at('2026-03-10 13:45:00');
    $this->app->instance(Clock::class, $this->clock);

    $this->owner = User::factory()->create();
    $this->cnpj = BrazilianDocuments::cnpj();
});

it('cria a organização em avaliação com assinatura em trial, matriz e associação de proprietário', function (): void {
    $membership = app(CreateOrganization::class)->handle($this->owner, onboardingDados(Cnpj::format($this->cnpj)));

    $organization = Organization::query()->findOrFail($membership->tenant_id);

    expect($organization->status)->toBe(OrganizationStatus::Trial)
        ->and($organization->document)->toBe($this->cnpj)
        ->and($organization->legal_name)->toBe('Horizonte Atacadista Distribuição Ltda')
        ->and($organization->trade_name)->toBe('Horizonte Atacadista')
        ->and($organization->slug)->toMatch('/^horizonte-atacadista-[a-z0-9]{6}$/')
        ->and($organization->email)->toBe('comercial@horizonte-atacadista.example')
        ->and($organization->phone)->toBe('1133224455');

    $subscription = Subscription::query()->with('plan')->where('organization_id', $organization->id)->sole();
    $trialEnd = CarbonImmutable::parse('2026-03-24 13:45:00', 'UTC');

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan->code)->toBe('profissional')
        ->and($subscription->trial_ends_at?->equalTo($trialEnd))->toBeTrue()
        ->and($subscription->current_period_start?->equalTo($this->clock->now()))->toBeTrue()
        ->and($subscription->current_period_end?->equalTo($trialEnd))->toBeTrue()
        ->and($subscription->cancelled_at)->toBeNull();

    [$branches, $memberships] = asTenant($organization, fn (): array => [
        Branch::query()->get(),
        Membership::query()->with('role')->get(),
    ]);

    expect($branches)->toHaveCount(1);
    $headquarters = $branches->first();
    expect($headquarters->code)->toBe('MATRIZ')
        ->and($headquarters->name)->toBe('Matriz')
        ->and($headquarters->document)->toBe($this->cnpj)
        ->and($headquarters->is_headquarters)->toBeTrue()
        ->and($headquarters->is_active)->toBeTrue();

    expect($memberships)->toHaveCount(1);
    $owner = $memberships->first();
    expect($owner->id)->toBe($membership->id)
        ->and($owner->user_id)->toBe($this->owner->id)
        ->and($owner->kind)->toBe(MembershipKind::Staff)
        ->and($owner->status)->toBe(MembershipStatus::Active)
        ->and($owner->role->key)->toBe(SystemRole::Owner->value)
        ->and($owner->branch_id)->toBe($headquarters->id)
        ->and($owner->buyer_company_id)->toBeNull();
});

it('usa o prazo de avaliação configurado no plano contratado', function (): void {
    DB::table('plans')->where('code', 'essencial')->update(['trial_days' => 30]);

    $membership = app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj, 'essencial'));

    $subscription = Subscription::query()->where('organization_id', $membership->tenant_id)->sole();

    expect($subscription->trial_ends_at?->equalTo(CarbonImmutable::parse('2026-04-09 13:45:00', 'UTC')))->toBeTrue();
});

it('cria os onze papéis padrão com permissões e limites de desconto de cada perfil', function (): void {
    $membership = app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj));

    $roles = asTenant($membership->tenant_id, fn () => Role::query()->get()->keyBy('key'));

    expect($roles)->toHaveCount(11)
        ->and($roles->keys()->sort()->values()->all())->toBe(collect(SystemRole::cases())->map->value->sort()->values()->all());

    // Persistência fiel ao modelo de cada papel do sistema.
    foreach (SystemRole::cases() as $template) {
        $role = $roles[$template->value];

        expect($role->name)->toBe($template->label())
            ->and($role->kind)->toBe($template->kind())
            ->and($role->permissions)->toBe(array_map(static fn (Permission $p): string => $p->value, $template->permissions()))
            ->and($role->max_discount_bps)->toBe($template->maxDiscountBps())
            ->and($role->is_system)->toBeTrue();
    }

    expect($roles->map->max_discount_bps->all())->toEqual([
        'owner' => 10_000, 'admin' => 10_000, 'sales_manager' => 1_500, 'sales_rep' => 500,
        'warehouse' => 0, 'finance' => 0, 'viewer' => 0,
        'buyer_admin' => 0, 'purchaser' => 0, 'requester' => 0, 'approver' => 0,
    ]);

    $staff = $roles->filter(fn (Role $r): bool => $r->kind === MembershipKind::Staff);
    $buyer = $roles->filter(fn (Role $r): bool => $r->kind === MembershipKind::Buyer);

    expect($staff->keys()->sort()->values()->all())->toBe(['admin', 'finance', 'owner', 'sales_manager', 'sales_rep', 'viewer', 'warehouse'])
        ->and($buyer->keys()->sort()->values()->all())->toBe(['approver', 'buyer_admin', 'purchaser', 'requester']);

    // Papéis de equipe nunca recebem permissões do portal, e vice-versa.
    foreach ($staff as $role) {
        expect(array_filter($role->permissions, static fn (string $p): bool => str_starts_with($p, 'portal.')))->toBe([]);
    }

    foreach ($buyer as $role) {
        expect($role->permissions)->not->toBeEmpty()
            ->each->toStartWith('portal.');
    }

    // Somente o proprietário gerencia a assinatura do Fornevia.
    expect($roles['owner']->permissions)->toHaveCount(28)
        ->toContain('subscription.manage', 'members.manage', 'organization.manage')
        ->and($roles['admin']->permissions)->toHaveCount(27)
        ->toContain('members.manage', 'organization.manage')
        ->not->toContain('subscription.manage')
        ->and($roles['viewer']->permissions)->not->toContain('orders.manage', 'members.manage')
        ->and($roles['sales_rep']->isPrivileged())->toBeFalse()
        ->and($roles['buyer_admin']->permissions)->toContain('portal.users', 'portal.budgets')
        ->and($roles['purchaser']->permissions)->toContain('portal.orders.place')->not->toContain('portal.approvals')
        ->and($roles['approver']->permissions)->toContain('portal.approvals')->not->toContain('portal.cart');
});

it('mantém papéis e matriz independentes quando o mesmo usuário cria duas organizações', function (): void {
    $first = app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj, 'profissional', 'Horizonte Atacadista'));
    $second = app(CreateOrganization::class)->handle($this->owner, onboardingDados(BrazilianDocuments::cnpj(), 'essencial', 'Vale Verde Suprimentos'));

    expect($first->tenant_id)->not->toBe($second->tenant_id);

    foreach ([$first, $second] as $membership) {
        [$roles, $branches, $owners] = asTenant($membership->tenant_id, fn (): array => [
            Role::query()->pluck('tenant_id')->unique()->all(),
            Branch::query()->count(),
            Membership::query()->pluck('id')->all(),
        ]);

        expect($roles)->toBe([$membership->tenant_id])
            ->and($branches)->toBe(1)
            ->and($owners)->toBe([$membership->id]);
    }

    expect(asTenant($first->tenant_id, fn (): int => Role::query()->count()))->toBe(11)
        ->and(asTenant($second->tenant_id, fn (): int => Role::query()->count()))->toBe(11);
});

it('registra a criação na auditoria do tenant e na auditoria da plataforma', function (): void {
    Context::add('correlation_id', 'onboarding-0001');

    $membership = app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj));
    $tenantId = $membership->tenant_id;

    $log = asTenant($tenantId, fn (): AuditLog => AuditLog::query()->sole());

    expect($log->action)->toBe('organization.created')
        ->and($log->subject_type)->toBe('organization')
        ->and($log->subject_id)->toBe($tenantId)
        ->and($log->actor_user_id)->toBe($this->owner->id)
        ->and($log->actor_membership_id)->toBeNull()
        ->and($log->data)->toEqual(['plan' => 'profissional', 'trial_ends_at' => '2026-03-24T13:45:00+00:00'])
        ->and($log->correlation_id)->toBe('onboarding-0001');

    $platform = DB::table('platform_audit_logs')->get();

    expect($platform)->toHaveCount(1);
    $entry = $platform->first();

    expect($entry->action)->toBe('organization.created')
        ->and($entry->organization_id)->toBe($tenantId)
        ->and($entry->actor_user_id)->toBe($this->owner->id)
        ->and(json_decode((string) $entry->data, true))->toBe(['plan' => 'profissional'])
        ->and($entry->reason)->toBeNull()
        ->and($entry->correlation_id)->toBe('onboarding-0001')
        ->and(CarbonImmutable::parse($entry->created_at)->equalTo($this->clock->now()))->toBeTrue();
});

it('recusa CNPJ já cadastrado, com ou sem formatação, sem criar dados parciais', function (): void {
    app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj));
    $competitor = User::factory()->create();

    expect(fn () => app(CreateOrganization::class)->handle($competitor, onboardingDados(Cnpj::format($this->cnpj), 'essencial', 'Outra Razão')))
        ->toThrow(function (OrganizationAlreadyRegistered $e): void {
            expect($e)->toBeInstanceOf(ConflictingState::class)
                ->and($e->errorCode())->toBe('organization_already_registered')
                ->and($e->getMessage())->toBe('Já existe uma organização cadastrada com este CNPJ.');
        });

    expect(Organization::query()->count())->toBe(1)
        ->and(Subscription::query()->count())->toBe(1)
        ->and(DB::table('platform_audit_logs')->count())->toBe(1)
        ->and(asTenant(Organization::query()->sole(), fn (): array => Membership::query()->pluck('user_id')->all()))->toBe([$this->owner->id]);
});

it('recusa plano indisponível para contratação sem criar a organização', function (Closure $prepare, string $plan): void {
    $prepare();

    expect(fn () => app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj, $plan)))
        ->toThrow(function (PlanUnavailable $e): void {
            expect($e->errorCode())->toBe('plan_unavailable');
        });

    expect(Organization::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(DB::table('platform_audit_logs')->count())->toBe(0);
})->with([
    'plano inativo' => [fn () => DB::table('plans')->where('code', 'corporativo')->update(['is_active' => false]), 'corporativo'],
    'plano não público' => [fn () => DB::table('plans')->where('code', 'corporativo')->update(['is_public' => false]), 'corporativo'],
    'plano inexistente' => [fn () => null, 'premium-plus'],
]);

it('desfaz todo o onboarding quando uma etapa falha e permite repetir com o mesmo CNPJ', function (): void {
    // A associação de proprietário é a última gravação antes da auditoria:
    // organização, assinatura, papéis e matriz já foram inseridos.
    $fail = true;
    Membership::creating(static function () use (&$fail): void {
        if ($fail) {
            throw new RuntimeException('Falha simulada ao gravar a associação.');
        }
    });

    expect(fn () => app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj)))
        ->toThrow(RuntimeException::class, 'Falha simulada');

    expect(Organization::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0)
        ->and(DB::table('platform_audit_logs')->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);

    $fail = false;

    $membership = app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj));

    expect(Organization::query()->sole()->id)->toBe($membership->tenant_id);
});

it('cria a organização a partir do contexto do próprio usuário e restaura esse contexto ao final', function (): void {
    [$membership, $settings, $active] = inContext(ExecutionContext::user($this->owner->id), function (): array {
        $membership = app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj));

        return [$membership, app(TenantDatabase::class)->activeDatabaseSettings(), app(CurrentContext::class)->get()];
    });

    expect($settings['context_kind'])->toBe('user')
        ->and($settings['tenant_id'])->toBe('')
        ->and($settings['user_id'])->toBe($this->owner->id)
        ->and($active?->equals(ExecutionContext::user($this->owner->id)))->toBeTrue();

    expect(Organization::query()->sole()->id)->toBe($membership->tenant_id)
        ->and(asTenant($membership->tenant_id, fn (): int => Role::query()->count()))->toBe(11);
});

it('recusa criar organização para outro usuário dentro de um contexto de usuário diferente', function (): void {
    $intruder = User::factory()->create();

    expect(fn () => inContext(
        ExecutionContext::user($intruder->id),
        fn () => app(CreateOrganization::class)->handle($this->owner, onboardingDados($this->cnpj)),
    ))->toThrow(TenantContextConflict::class);

    expect(Organization::query()->count())->toBe(0)
        ->and(Subscription::query()->count())->toBe(0);
});
