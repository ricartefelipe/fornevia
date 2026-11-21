<?php

declare(strict_types=1);

use App\Domain\Platform\Exceptions\ModuleNotIncluded;
use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Domain\Platform\Exceptions\SubscriptionInactive;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\SystemRole;
use App\Enums\Platform\PlatformModule;
use App\Enums\Platform\SubscriptionStatus;
use App\Models\Identity\Branch;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Models\Platform\Plan;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionModule;
use App\Services\Platform\PlanLimits;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use App\Support\Tenancy\Exceptions\TenantContextMissing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/**
 * Executa o callback com PlanLimits no contexto de sistema do tenant.
 *
 * @template T
 *
 * @param  Closure(PlanLimits): T  $callback
 * @return T
 */
function limitesNoTenant(TenantScenario $tenant, Closure $callback): mixed
{
    return $tenant->system(fn (): mixed => $callback(app(PlanLimits::class)));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function limitesConvite(TenantScenario $tenant, string $email, array $attributes = []): Invitation
{
    return $tenant->system(function () use ($email, $attributes): Invitation {
        $invitation = new Invitation;
        $invitation->forceFill([
            'email' => $email,
            'kind' => MembershipKind::Staff,
            'role_id' => Role::query()->where('key', SystemRole::SalesRep->value)->value('id'),
            'token_hash' => Invitation::hashToken(Str::random(64)),
            'expires_at' => app(Clock::class)->now()->addDays(7),
            ...$attributes,
        ])->save();

        return $invitation;
    });
}

/**
 * @param  array<string, mixed>  $attributes
 */
function limitesAjustarPlano(string $code, array $attributes): void
{
    DB::table('plans')->where('code', $code)->update($attributes);
}

function limitesAssinatura(TenantScenario $tenant): Subscription
{
    return Subscription::query()
        ->where('organization_id', $tenant->organization->id)
        ->where('status', '<>', SubscriptionStatus::Cancelled->value)
        ->sole();
}

/**
 * @param  int  $count  Associações de equipe ativas a adicionar além do proprietário.
 */
function limitesEquipe(TenantScenario $tenant, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $tenant->addStaff(SystemRole::SalesRep);
    }
}

beforeEach(function (): void {
    $this->clock = FrozenClock::at('2026-03-10 15:00:00');
    $this->app->instance(Clock::class, $this->clock);
});

describe('usuários da equipe', function (): void {
    it('conta convites pendentes junto com os usuários ativos', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesEquipe($tenant, 2);
        limitesConvite($tenant, 'ana.ribeiro@horizonte.example');

        // 3 ativos + 1 convite pendente = 4 de 5.
        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember());

        limitesConvite($tenant, 'bruno.teixeira@horizonte.example');

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember()))
            ->toThrow(function (PlanLimitReached $e): void {
                expect($e->details())->toBe(['limit' => 5, 'resource' => 'staff_users'])
                    ->and($e->errorCode())->toBe('plan_limit_reached')
                    ->and($e->getMessage())->toContain('Essencial')->toContain('5 usuários')->toContain('convites pendentes');
            });
    });

    it('desconsidera convites expirados, revogados, aceitos e de compradores e associações revogadas ou de compradores', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesEquipe($tenant, 3);
        $now = $this->clock->now();

        $revoked = $tenant->addStaff(SystemRole::Warehouse);
        $tenant->system(fn () => Membership::query()->whereKey($revoked->id)->update([
            'status' => MembershipStatus::Revoked->value,
            'revoked_at' => $now,
            'revoke_reason' => 'Desligamento',
        ]));

        $company = $tenant->addBuyerCompany();
        $tenant->addBuyerUser($company);
        $tenant->addBuyerUser($company, SystemRole::Approver);

        limitesConvite($tenant, 'expira.agora@horizonte.example', ['expires_at' => $now]);
        limitesConvite($tenant, 'revogado@horizonte.example', ['revoked_at' => $now->subHour()]);
        limitesConvite($tenant, 'aceito@horizonte.example', ['accepted_at' => $now->subDay()]);
        limitesConvite($tenant, 'compras@compradora.example', [
            'kind' => MembershipKind::Buyer,
            'buyer_company_id' => $company->id,
            'role_id' => $tenant->role(SystemRole::Purchaser)->id,
        ]);

        // Somente proprietário + 3 ativos ocupam vagas: 4 de 5.
        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember());

        limitesConvite($tenant, 'carla.mendes@horizonte.example', ['expires_at' => $now->addSecond()]);

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember()))
            ->toThrow(PlanLimitReached::class);
    });

    it('no aceite de convite verifica apenas usuários ativos, pois o convite já ocupa a vaga', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesEquipe($tenant, 3);
        limitesConvite($tenant, 'daniel.souza@horizonte.example');

        // 4 ativos + 1 pendente: novo convite recusado, mas o aceite do pendente é permitido.
        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember()))
            ->toThrow(PlanLimitReached::class);
        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertStaffSeatForAcceptance());

        limitesEquipe($tenant, 1);

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertStaffSeatForAcceptance()))
            ->toThrow(function (PlanLimitReached $e): void {
                expect($e->details())->toBe(['limit' => 5, 'resource' => 'staff_users']);
            });
    });

    it('recusa o aceite quando o limite do plano foi reduzido abaixo dos usuários ativos', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesEquipe($tenant, 2);

        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertStaffSeatForAcceptance());

        limitesAjustarPlano('essencial', ['max_staff_users' => 3]);

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertStaffSeatForAcceptance()))
            ->toThrow(function (PlanLimitReached $e): void {
                expect($e->details())->toBe(['limit' => 3, 'resource' => 'staff_users']);
            });
    });

    it('não limita usuários quando o plano não define limite', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesEquipe($tenant, 4);
        limitesConvite($tenant, 'eduarda.lima@horizonte.example');

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember()))
            ->toThrow(PlanLimitReached::class);

        limitesAjustarPlano('essencial', ['max_staff_users' => null]);

        expect(fn () => limitesNoTenant($tenant, function (PlanLimits $limits): void {
            $limits->assertCanAddStaffMember();
            $limits->assertStaffSeatForAcceptance();
        }))->not->toThrow(PlanLimitReached::class);
    });

    it('conta apenas os usuários do próprio tenant', function (): void {
        $full = TenantScenario::create('essencial');
        limitesEquipe($full, 4);
        $other = TenantScenario::create('essencial');

        expect(fn () => limitesNoTenant($full, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember()))
            ->toThrow(PlanLimitReached::class);

        limitesNoTenant($other, fn (PlanLimits $limits) => $limits->assertCanAddStaffMember());
    });
});

describe('filiais', function (): void {
    it('conta a matriz e recusa filial acima do limite do plano', function (): void {
        $essencial = TenantScenario::create('essencial');

        expect(fn () => limitesNoTenant($essencial, fn (PlanLimits $limits) => $limits->assertCanAddBranch()))
            ->toThrow(function (PlanLimitReached $e): void {
                expect($e->details())->toBe(['limit' => 1, 'resource' => 'branches'])
                    ->and($e->getMessage())->toContain('1 filiais');
            });

        $profissional = TenantScenario::create('profissional');
        $profissional->system(fn () => Branch::factory()->count(3)->create());

        // Matriz + 3 filiais = 4 de 5.
        limitesNoTenant($profissional, fn (PlanLimits $limits) => $limits->assertCanAddBranch());

        $profissional->system(fn () => Branch::factory()->create());

        expect(fn () => limitesNoTenant($profissional, fn (PlanLimits $limits) => $limits->assertCanAddBranch()))
            ->toThrow(function (PlanLimitReached $e): void {
                expect($e->details())->toBe(['limit' => 5, 'resource' => 'branches']);
            });
    });

    it('não limita filiais quando o plano não define limite', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesAjustarPlano('essencial', ['max_branches' => null]);
        $tenant->system(fn () => Branch::factory()->count(4)->create());

        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertCanAddBranch());
    })->throwsNoExceptions();
});

describe('volume mensal de pedidos', function (): void {
    it('incrementa o contador até o limite e recusa o pedido excedente sem alterar o contador', function (): void {
        $tenant = TenantScenario::create('profissional');
        limitesAjustarPlano('profissional', ['max_orders_per_month' => 3]);

        for ($i = 0; $i < 3; $i++) {
            limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota());
        }

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota()))
            ->toThrow(function (PlanLimitReached $e): void {
                expect($e->details())->toBe(['limit' => 3, 'resource' => 'orders_per_month'])
                    ->and($e->getMessage())->toContain('3 pedidos')->toContain('Profissional');
            });

        $counters = $tenant->system(fn () => DB::table('tenant_usage_counters')->get());

        expect($counters)->toHaveCount(1)
            ->and($counters->first()->period)->toBe('2026-03-01')
            ->and($counters->first()->orders_confirmed)->toBe(3)
            ->and($counters->first()->tenant_id)->toBe($tenant->organization->id);
    });

    it('não consome a cota quando a confirmação do pedido é desfeita', function (): void {
        $tenant = TenantScenario::create('profissional');

        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota());

        expect(fn () => limitesNoTenant($tenant, function (PlanLimits $limits): never {
            $limits->consumeOrderQuota();

            throw new RuntimeException('Falha simulada na confirmação do pedido.');
        }))->toThrow(RuntimeException::class);

        expect($tenant->system(fn () => DB::table('tenant_usage_counters')->value('orders_confirmed')))->toBe(1);
    });

    it('não limita pedidos quando o plano não define volume mensal', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesAjustarPlano('essencial', ['max_orders_per_month' => null]);

        for ($i = 0; $i < 5; $i++) {
            limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota());
        }

        expect($tenant->system(fn () => DB::table('tenant_usage_counters')->value('orders_confirmed')))->toBe(5);
    });

    it('apura o volume por mês civil no fuso horário do negócio', function (): void {
        $this->clock = FrozenClock::at('2026-03-31 12:00:00');
        $this->app->instance(Clock::class, $this->clock);

        $tenant = TenantScenario::create('profissional');
        limitesAjustarPlano('profissional', ['max_orders_per_month' => 2]);

        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota());
        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota());

        // 02:30 UTC de 1º de abril ainda é 31 de março em São Paulo.
        $this->clock->advance('PT14H30M');

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota()))
            ->toThrow(PlanLimitReached::class);

        // 03:30 UTC: início de abril no fuso do negócio, cota renovada.
        $this->clock->advance('PT1H');

        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->consumeOrderQuota());

        expect($tenant->system(fn () => DB::table('tenant_usage_counters')->orderBy('period')->pluck('orders_confirmed', 'period')->all()))
            ->toBe(['2026-03-01' => 2, '2026-04-01' => 1]);
    });

    it('mantém contadores separados por tenant', function (): void {
        $first = TenantScenario::create('essencial');
        $second = TenantScenario::create('essencial');
        limitesAjustarPlano('essencial', ['max_orders_per_month' => 1]);

        limitesNoTenant($first, fn (PlanLimits $limits) => $limits->consumeOrderQuota());

        expect(fn () => limitesNoTenant($first, fn (PlanLimits $limits) => $limits->consumeOrderQuota()))
            ->toThrow(PlanLimitReached::class);

        limitesNoTenant($second, fn (PlanLimits $limits) => $limits->consumeOrderQuota());

        expect($first->system(fn () => DB::table('tenant_usage_counters')->pluck('orders_confirmed', 'tenant_id')->all()))
            ->toBe([$first->organization->id => 1])
            ->and($second->system(fn () => DB::table('tenant_usage_counters')->pluck('orders_confirmed', 'tenant_id')->all()))
            ->toBe([$second->organization->id => 1]);
    });
});

describe('módulos', function (): void {
    it('libera módulos incluídos no plano e recusa os demais', function (): void {
        $tenant = TenantScenario::create('profissional');

        $available = limitesNoTenant($tenant, fn (PlanLimits $limits): array => array_map(
            static fn (PlatformModule $m): bool => $limits->hasModule($m),
            array_combine(array_map(static fn (PlatformModule $m): string => $m->value, PlatformModule::cases()), PlatformModule::cases()),
        ));

        expect($available)->toBe([
            'quotes' => true, 'approvals' => true, 'returns' => true, 'imports' => true,
            'api' => false, 'webhooks' => false,
        ]);

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertModule(PlatformModule::Api)))
            ->toThrow(function (ModuleNotIncluded $e): void {
                expect($e->details())->toBe(['module' => 'api'])
                    ->and($e->errorCode())->toBe('module_not_included')
                    ->and($e->getMessage())->toContain('API de integração');
            });
    });

    it('libera módulo adicional ativo e volta a recusar após a desativação', function (): void {
        $tenant = TenantScenario::create('essencial');
        $subscription = limitesAssinatura($tenant);

        expect(limitesNoTenant($tenant, fn (PlanLimits $limits): bool => $limits->hasModule(PlatformModule::Quotes)))->toBeFalse();

        $addon = new SubscriptionModule;
        $addon->forceFill([
            'subscription_id' => $subscription->id,
            'module_code' => PlatformModule::Quotes->value,
            'price_cents' => 39_000,
            'activated_at' => $this->clock->now(),
        ])->save();

        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertModule(PlatformModule::Quotes));
        expect(limitesNoTenant($tenant, fn (PlanLimits $limits): array => [
            $limits->hasModule(PlatformModule::Quotes),
            $limits->hasModule(PlatformModule::Approvals),
        ]))->toBe([true, false]);

        $addon->forceFill(['deactivated_at' => $this->clock->now()->addDays(30)])->save();

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertModule(PlatformModule::Quotes)))
            ->toThrow(ModuleNotIncluded::class)
            ->and(limitesNoTenant($tenant, fn (PlanLimits $limits): bool => $limits->hasModule(PlatformModule::Quotes)))->toBeFalse();
    });
});

describe('situação da assinatura', function (): void {
    it('bloqueia toda escrita a partir do término do período de avaliação', function (): void {
        $tenant = TenantScenario::create('profissional');

        // Trial de 14 dias a partir de 10/03 15:00 UTC: termina em 24/03 15:00 UTC.
        $this->clock->advance('P13DT23H59M59S');
        limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertWritable());

        $this->clock->advance('PT1S');

        $operations = [
            'assertWritable' => fn (PlanLimits $limits) => $limits->assertWritable(),
            'assertCanAddStaffMember' => fn (PlanLimits $limits) => $limits->assertCanAddStaffMember(),
            'assertStaffSeatForAcceptance' => fn (PlanLimits $limits) => $limits->assertStaffSeatForAcceptance(),
            'assertCanAddBranch' => fn (PlanLimits $limits) => $limits->assertCanAddBranch(),
            'consumeOrderQuota' => fn (PlanLimits $limits) => $limits->consumeOrderQuota(),
        ];

        foreach ($operations as $name => $operation) {
            expect(fn () => limitesNoTenant($tenant, $operation))
                ->toThrow(SubscriptionInactive::class, 'O período de avaliação terminou. Contrate um plano para continuar.', $name);
        }

        // Leitura da assinatura continua disponível (ex.: painel e tela de contratação).
        expect(limitesNoTenant($tenant, fn (PlanLimits $limits): string => $limits->current()->plan->code))->toBe('profissional')
            ->and($tenant->system(fn (): int => DB::table('tenant_usage_counters')->count()))->toBe(0);
    });

    it('libera a escrita quando a assinatura é ativada após o período de avaliação', function (): void {
        $tenant = TenantScenario::create('profissional');
        $this->clock->advance('P20D');

        expect(fn () => limitesNoTenant($tenant, fn (PlanLimits $limits) => $limits->assertWritable()))
            ->toThrow(SubscriptionInactive::class);

        limitesAssinatura($tenant)->forceFill(['status' => SubscriptionStatus::Active])->save();

        limitesNoTenant($tenant, function (PlanLimits $limits): void {
            $limits->assertWritable();
            $limits->assertCanAddBranch();
            $limits->consumeOrderQuota();
        });

        expect($tenant->system(fn () => DB::table('tenant_usage_counters')->value('orders_confirmed')))->toBe(1);
    });

    it('trata assinatura cancelada como ausência de assinatura vigente', function (): void {
        $tenant = TenantScenario::create('profissional');
        limitesAssinatura($tenant)->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => $this->clock->now(),
        ])->save();

        $operations = [
            'current' => fn (PlanLimits $limits) => $limits->current(),
            'assertWritable' => fn (PlanLimits $limits) => $limits->assertWritable(),
            'assertCanAddStaffMember' => fn (PlanLimits $limits) => $limits->assertCanAddStaffMember(),
            'assertStaffSeatForAcceptance' => fn (PlanLimits $limits) => $limits->assertStaffSeatForAcceptance(),
            'assertCanAddBranch' => fn (PlanLimits $limits) => $limits->assertCanAddBranch(),
            'consumeOrderQuota' => fn (PlanLimits $limits) => $limits->consumeOrderQuota(),
            'assertModule' => fn (PlanLimits $limits) => $limits->assertModule(PlatformModule::Quotes),
        ];

        foreach ($operations as $name => $operation) {
            expect(fn () => limitesNoTenant($tenant, $operation))
                ->toThrow(SubscriptionInactive::class, 'Organização sem assinatura vigente.', $name);
        }

        expect(limitesNoTenant($tenant, fn (PlanLimits $limits): bool => $limits->hasModule(PlatformModule::Quotes)))->toBeFalse()
            ->and($tenant->system(fn (): int => DB::table('tenant_usage_counters')->count()))->toBe(0);
    });

    it('passa a usar a nova assinatura contratada após o cancelamento da anterior', function (): void {
        $tenant = TenantScenario::create('essencial');
        limitesAssinatura($tenant)->forceFill([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => $this->clock->now(),
        ])->save();

        $renewal = new Subscription;
        $renewal->forceFill([
            'organization_id' => $tenant->organization->id,
            'plan_id' => Plan::query()->where('code', 'corporativo')->value('id'),
            'status' => SubscriptionStatus::Active,
            'current_period_start' => $this->clock->now(),
            'current_period_end' => $this->clock->now()->addMonth(),
        ])->save();

        $current = limitesNoTenant($tenant, function (PlanLimits $limits): Subscription {
            $limits->assertModule(PlatformModule::Webhooks);
            $limits->assertCanAddBranch();

            return $limits->current();
        });

        expect($current->id)->toBe($renewal->id)
            ->and($current->plan->code)->toBe('corporativo');
    });

    it('exige tenant no contexto de execução', function (): void {
        TenantScenario::create('essencial');

        app(PlanLimits::class)->current();
    })->throws(TenantContextMissing::class);
});
