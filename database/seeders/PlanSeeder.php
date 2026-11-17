<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Platform\PlatformModule;
use App\Models\Platform\Plan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Planos e módulos da plataforma. Valores são hipóteses de produto,
 * ajustáveis pelo administrador; o seeder é idempotente (por código).
 */
final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        foreach ([
            [PlatformModule::Quotes, 39_000],
            [PlatformModule::Approvals, 39_000],
            [PlatformModule::Returns, 29_000],
            [PlatformModule::Imports, 19_000],
            [PlatformModule::Api, 49_000],
            [PlatformModule::Webhooks, 29_000],
        ] as [$module, $price]) {
            DB::table('platform_modules')->upsert(
                [['code' => $module->value, 'name' => $module->label(), 'addon_price_cents' => $price, 'created_at' => $now, 'updated_at' => $now]],
                ['code'],
                ['name', 'addon_price_cents', 'updated_at'],
            );
        }

        $plans = [
            [
                'code' => 'essencial', 'name' => 'Essencial', 'sort_order' => 10,
                'description' => 'Portal do comprador, catálogo, preços por cliente, pedidos, estoque e crédito.',
                'monthly_price_cents' => 89_000, 'setup_fee_cents' => 150_000,
                'max_staff_users' => 5, 'max_branches' => 1, 'max_orders_per_month' => 500,
                'modules' => [],
            ],
            [
                'code' => 'profissional', 'name' => 'Profissional', 'sort_order' => 20,
                'description' => 'Inclui cotações, aprovações por alçada, devoluções e importação CSV.',
                'monthly_price_cents' => 249_000, 'setup_fee_cents' => 450_000,
                'max_staff_users' => 20, 'max_branches' => 5, 'max_orders_per_month' => 3_000,
                'modules' => ['quotes', 'approvals', 'returns', 'imports'],
            ],
            [
                'code' => 'corporativo', 'name' => 'Corporativo', 'sort_order' => 30,
                'description' => 'Todos os módulos, API de integração e webhooks.',
                'monthly_price_cents' => 690_000, 'setup_fee_cents' => 1_200_000,
                'max_staff_users' => 100, 'max_branches' => 30, 'max_orders_per_month' => 20_000,
                'modules' => array_map(static fn (PlatformModule $m): string => $m->value, PlatformModule::cases()),
            ],
        ];

        foreach ($plans as $attributes) {
            $plan = Plan::query()->firstOrNew(['code' => $attributes['code']]);
            $plan->fill([...$attributes, 'trial_days' => 14, 'is_public' => true, 'is_active' => true])->save();
        }
    }
}
