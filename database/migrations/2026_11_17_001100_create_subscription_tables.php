<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assinatura do distribuidor no Fornevia (receita da plataforma).
 *
 * Totalmente separada das vendas do distribuidor aos seus clientes: usa
 * organization_id (não tenant_id), não compartilha tabelas com pedidos,
 * faturas comerciais ou contas a receber do tenant e é administrada pela
 * plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_modules', function (Blueprint $table): void {
            $table->string('code', 30)->primary();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->bigInteger('addon_price_cents')->default(0);
            $table->timestampsTz();
        });

        Schema::create('plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('name', 80);
            $table->text('description')->nullable();
            $table->bigInteger('monthly_price_cents');
            $table->bigInteger('setup_fee_cents')->default(0);
            $table->integer('max_staff_users')->nullable();
            $table->integer('max_branches')->nullable();
            $table->integer('max_orders_per_month')->nullable();
            $table->jsonb('modules')->default('[]');
            $table->integer('trial_days')->default(14);
            $table->boolean('is_public')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement('alter table plans add constraint plans_prices_check check (monthly_price_cents >= 0 and setup_fee_cents >= 0)');
        DB::statement('alter table plans add constraint plans_limits_check check (coalesce(max_staff_users, 1) > 0 and coalesce(max_branches, 1) > 0 and coalesce(max_orders_per_month, 1) > 0)');

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->string('status', 12);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table subscriptions add constraint subscriptions_status_check check (status in ('trialing', 'active', 'past_due', 'cancelled'))");
        DB::statement("create unique index subscriptions_one_current on subscriptions (organization_id) where status <> 'cancelled'");

        Schema::create('subscription_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('module_code', 30);
            $table->foreign('module_code')->references('code')->on('platform_modules')->restrictOnDelete();
            $table->bigInteger('price_cents');
            $table->timestampTz('activated_at');
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement('create unique index subscription_modules_active on subscription_modules (subscription_id, module_code) where deactivated_at is null');

        // Contador mensal de pedidos confirmados por tenant, incrementado de
        // forma condicional na mesma transação da confirmação.
        Schema::create('tenant_usage_counters', function (Blueprint $table): void {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->date('period');
            $table->integer('orders_confirmed')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['tenant_id', 'period']);
        });
        DB::statement('alter table tenant_usage_counters add constraint tenant_usage_counters_non_negative check (orders_confirmed >= 0)');
        DB::statement('alter table tenant_usage_counters enable row level security');
        DB::statement('alter table tenant_usage_counters force row level security');
        DB::statement('create policy tenant_isolation on tenant_usage_counters as permissive for all
            using (tenant_id = app_current_tenant()) with check (tenant_id = app_current_tenant())');

        Schema::create('platform_audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->string('action', 80);
            $table->jsonb('data')->default('{}');
            $table->text('reason')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });
        $app = (string) config('database.roles.app');
        DB::statement("revoke update, delete, truncate on platform_audit_logs from {$app}");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_audit_logs');
        Schema::dropIfExists('tenant_usage_counters');
        Schema::dropIfExists('subscription_modules');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('platform_modules');
    }
};
