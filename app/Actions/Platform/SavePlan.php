<?php

declare(strict_types=1);

namespace App\Actions\Platform;

use App\Enums\Platform\PlatformModule;
use App\Models\Identity\User;
use App\Models\Platform\Plan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria ou altera um plano da plataforma. Alterações valem para novas
 * contratações e para os limites verificados a partir de então.
 */
final class SavePlan
{
    /**
     * @param  array{code: string, name: string, description?: string|null, monthly_price_cents: int, setup_fee_cents: int,
     *               max_staff_users: int|null, max_branches: int|null, max_orders_per_month: int|null,
     *               modules: list<string>, trial_days: int, is_public: bool, is_active: bool, sort_order?: int}  $attributes
     */
    public function handle(User $actor, ?Plan $plan, array $attributes): Plan
    {
        if (! $actor->is_platform_admin) {
            throw new AuthorizationException('Somente administradores da plataforma gerenciam planos.');
        }

        $modules = array_values(array_unique(array_filter(
            $attributes['modules'],
            static fn (string $code): bool => PlatformModule::tryFrom($code) !== null,
        )));

        return DB::transaction(function () use ($actor, $plan, $attributes, $modules): Plan {
            $plan ??= new Plan;
            $tracked = ['monthly_price_cents', 'setup_fee_cents', 'max_staff_users', 'max_branches', 'max_orders_per_month', 'modules'];
            $before = $plan->exists ? $plan->only($tracked) : null;
            $plan->fill([...$attributes, 'modules' => $modules])->save();

            DB::table('platform_audit_logs')->insert([
                'id' => (string) Str::uuid7(),
                'actor_user_id' => $actor->id,
                'action' => $before === null ? 'plan.created' : 'plan.updated',
                'data' => json_encode(['plan' => $plan->code, 'before' => $before, 'after' => $plan->only($tracked)], JSON_THROW_ON_ERROR),
                'correlation_id' => Context::get('correlation_id'),
                'created_at' => now(),
            ]);

            return $plan;
        });
    }
}
