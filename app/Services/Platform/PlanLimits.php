<?php

declare(strict_types=1);

namespace App\Services\Platform;

use App\Domain\Platform\Exceptions\ModuleNotIncluded;
use App\Domain\Platform\Exceptions\PlanLimitReached;
use App\Domain\Platform\Exceptions\SubscriptionInactive;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Platform\PlatformModule;
use App\Enums\Platform\SubscriptionStatus;
use App\Models\Identity\Branch;
use App\Models\Identity\Invitation;
use App\Models\Identity\Membership;
use App\Models\Platform\Subscription;
use App\Models\Platform\SubscriptionModule;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aplicação efetiva dos limites do plano contratado pelo distribuidor.
 *
 * Limites de usuários e filiais são verificados com a assinatura bloqueada
 * (FOR UPDATE), serializando inclusões concorrentes do mesmo tenant. O
 * volume mensal de pedidos usa incremento condicional em contador.
 */
final readonly class PlanLimits
{
    public function __construct(
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    public function current(): Subscription
    {
        return $this->query()->with(['plan', 'modules'])->firstOr(
            static fn () => throw new SubscriptionInactive('Organização sem assinatura vigente.'),
        );
    }

    public function assertWritable(): Subscription
    {
        $subscription = $this->current();
        $this->guardWritable($subscription);

        return $subscription;
    }

    public function assertCanAddStaffMember(): void
    {
        $subscription = $this->lock();
        $limit = $subscription->plan->max_staff_users;

        if ($limit === null) {
            return;
        }

        $active = $this->activeStaffCount();
        $pending = Invitation::query()
            ->where('kind', MembershipKind::Staff->value)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $this->clock->now())
            ->count();

        if ($active + $pending >= $limit) {
            throw new PlanLimitReached(
                "O plano {$subscription->plan->name} permite até {$limit} usuários na equipe (incluindo convites pendentes).",
                ['limit' => $limit, 'resource' => 'staff_users'],
            );
        }
    }

    /**
     * No aceite, o convite pendente já ocupava a vaga; verifica apenas se os
     * usuários ativos não atingiram o limite (ex.: limite reduzido depois).
     */
    public function assertStaffSeatForAcceptance(): void
    {
        $subscription = $this->lock();
        $limit = $subscription->plan->max_staff_users;

        if ($limit !== null && $this->activeStaffCount() >= $limit) {
            throw new PlanLimitReached(
                "O plano {$subscription->plan->name} permite até {$limit} usuários na equipe.",
                ['limit' => $limit, 'resource' => 'staff_users'],
            );
        }
    }

    public function assertCanAddBranch(): void
    {
        $subscription = $this->lock();
        $limit = $subscription->plan->max_branches;

        if ($limit !== null && Branch::query()->count() >= $limit) {
            throw new PlanLimitReached(
                "O plano {$subscription->plan->name} permite até {$limit} filiais.",
                ['limit' => $limit, 'resource' => 'branches'],
            );
        }
    }

    /**
     * Consome uma unidade do volume mensal de pedidos. Deve ser chamado na
     * mesma transação da confirmação do pedido (e por último, para manter o
     * lock do contador pelo menor tempo).
     */
    public function consumeOrderQuota(): void
    {
        $subscription = $this->assertWritable();
        $limit = $subscription->plan->max_orders_per_month;
        $tenantId = $this->context->tenantId();
        $period = $this->clock->today()->startOfMonth()->toDateString();

        DB::insert(
            'insert into tenant_usage_counters (tenant_id, period, orders_confirmed) values (?, ?, 0) on conflict do nothing',
            [$tenantId, $period],
        );

        $updated = DB::update(
            'update tenant_usage_counters set orders_confirmed = orders_confirmed + 1, updated_at = now()
             where tenant_id = ? and period = ? and (?::integer is null or orders_confirmed < ?::integer)',
            [$tenantId, $period, $limit, $limit],
        );

        if ($updated === 0) {
            throw new PlanLimitReached(
                "O volume mensal de {$limit} pedidos do plano {$subscription->plan->name} foi atingido.",
                ['limit' => $limit, 'resource' => 'orders_per_month'],
            );
        }
    }

    public function assertModule(PlatformModule $module): void
    {
        $subscription = $this->current();

        if ($subscription->plan->includesModule($module)) {
            return;
        }

        $addon = $subscription->modules->first(
            static fn (SubscriptionModule $m): bool => $m->module_code === $module->value && $m->deactivated_at === null,
        );

        if ($addon === null) {
            throw new ModuleNotIncluded("O módulo {$module->label()} não está incluído no plano.", ['module' => $module->value]);
        }
    }

    public function hasModule(PlatformModule $module): bool
    {
        try {
            $this->assertModule($module);

            return true;
        } catch (ModuleNotIncluded|SubscriptionInactive) {
            return false;
        }
    }

    private function activeStaffCount(): int
    {
        return Membership::query()
            ->where('kind', MembershipKind::Staff->value)
            ->where('status', MembershipStatus::Active->value)
            ->count();
    }

    private function lock(): Subscription
    {
        $subscription = $this->query()->lockForUpdate()->firstOr(
            static fn () => throw new SubscriptionInactive('Organização sem assinatura vigente.'),
        );
        $subscription->load('plan');
        $this->guardWritable($subscription);

        return $subscription;
    }

    private function guardWritable(Subscription $subscription): void
    {
        if ($subscription->status === SubscriptionStatus::Cancelled) {
            throw new SubscriptionInactive('A assinatura foi cancelada.');
        }

        if ($subscription->status === SubscriptionStatus::Trialing
            && $subscription->trial_ends_at !== null
            && $subscription->trial_ends_at->lessThanOrEqualTo($this->clock->now())) {
            throw new SubscriptionInactive('O período de avaliação terminou. Contrate um plano para continuar.');
        }
    }

    /**
     * @return Builder<Subscription>
     */
    private function query(): Builder
    {
        return Subscription::query()
            ->where('organization_id', $this->context->tenantId())
            ->where('status', '<>', SubscriptionStatus::Cancelled->value);
    }
}
