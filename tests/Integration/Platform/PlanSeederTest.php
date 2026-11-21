<?php

declare(strict_types=1);

use App\Enums\Platform\PlatformModule;
use App\Models\Platform\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Support\Facades\DB;

it('cadastra os três planos públicos com limites, preços e módulos de referência', function (): void {
    (new PlanSeeder)->run();

    $plans = Plan::query()->orderBy('sort_order')->get()->keyBy('code');

    expect($plans->keys()->all())->toBe(['essencial', 'profissional', 'corporativo']);

    expect($plans->map(fn (Plan $p): array => [
        $p->monthly_price_cents, $p->setup_fee_cents, $p->max_staff_users, $p->max_branches, $p->max_orders_per_month,
    ])->all())->toBe([
        'essencial' => [89_000, 150_000, 5, 1, 500],
        'profissional' => [249_000, 450_000, 20, 5, 3_000],
        'corporativo' => [690_000, 1_200_000, 100, 30, 20_000],
    ]);

    expect($plans['essencial']->modules)->toBe([])
        ->and($plans['profissional']->modules)->toBe(['quotes', 'approvals', 'returns', 'imports'])
        ->and($plans['corporativo']->modules)->toBe(array_map(static fn (PlatformModule $m): string => $m->value, PlatformModule::cases()));

    foreach ($plans as $plan) {
        expect($plan->trial_days)->toBe(14)
            ->and($plan->is_public)->toBeTrue()
            ->and($plan->is_active)->toBeTrue();
    }

    expect(DB::table('platform_modules')->orderBy('code')->pluck('addon_price_cents', 'code')->all())->toBe([
        'api' => 49_000,
        'approvals' => 39_000,
        'imports' => 19_000,
        'quotes' => 39_000,
        'returns' => 29_000,
        'webhooks' => 29_000,
    ]);
});

it('é idempotente: reexecutar não duplica registros e preserva os identificadores', function (): void {
    (new PlanSeeder)->run();
    $ids = Plan::query()->pluck('id', 'code')->all();

    (new PlanSeeder)->run();
    (new PlanSeeder)->run();

    expect(Plan::query()->count())->toBe(3)
        ->and(Plan::query()->pluck('id', 'code')->all())->toEqual($ids)
        ->and(DB::table('platform_modules')->count())->toBe(count(PlatformModule::cases()));
});

it('não altera planos criados pela plataforma com outros códigos', function (): void {
    (new PlanSeeder)->run();

    $custom = new Plan;
    $custom->fill([
        'code' => 'atacado-regional',
        'name' => 'Atacado Regional',
        'monthly_price_cents' => 159_000,
        'setup_fee_cents' => 0,
        'max_staff_users' => 12,
        'max_branches' => 3,
        'max_orders_per_month' => 1_500,
        'modules' => ['quotes'],
        'trial_days' => 7,
        'is_public' => false,
        'is_active' => true,
    ])->save();

    (new PlanSeeder)->run();

    $reloaded = Plan::query()->where('code', 'atacado-regional')->sole();

    expect(Plan::query()->count())->toBe(4)
        ->and($reloaded->id)->toBe($custom->id)
        ->and($reloaded->monthly_price_cents)->toBe(159_000)
        ->and($reloaded->trial_days)->toBe(7)
        ->and($reloaded->is_public)->toBeFalse()
        ->and($reloaded->modules)->toBe(['quotes']);
});
