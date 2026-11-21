<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aprovação interna de compras e orçamento por centro de custo
 * (RF-050, RN-050 a RN-056).
 *
 * - approval_rules / approval_rule_levels / approval_rule_level_approvers:
 *   regras da empresa compradora (critérios e níveis com quórum);
 * - approval_delegations: delegação com prazo;
 * - order_approval_steps: retrato dos níveis e aprovadores na submissão do
 *   pedido (alterar a regra não muda pedidos já submetidos);
 * - order_approval_decisions: decisões (append-only); invalidação registra
 *   a data, sem apagar o histórico;
 * - cost_center_budgets: projeção mantida por budget_movements (ADR-005),
 *   com reservado + consumido <= valor garantido no banco.
 *
 * Todas as tabelas são da empresa compradora (RLS required): o comprador
 * administra as da própria empresa; a equipe do distribuidor lê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            $table->string('name', 120);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->bigInteger('min_total_cents')->nullable();
            TenantSchema::reference($table, 'cost_center_id', 'cost_centers', nullable: true);
            TenantSchema::reference($table, 'buyer_unit_id', 'buyer_units', nullable: true);
            TenantSchema::reference($table, 'category_id', 'categories', nullable: true);
            $table->string('requester_role_key', 60)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'buyer_company_id', 'is_active', 'priority'], 'approval_rules_lookup_index');
        });
        DB::statement('alter table approval_rules add constraint approval_rules_values_check check (
            coalesce(min_total_cents, 0) >= 0 and priority between -1000 and 1000)');
        TenantSchema::enableRowLevelSecurity('approval_rules', 'required');

        Schema::create('approval_rule_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'approval_rule_id', 'approval_rules', cascade: true);
            $table->smallInteger('level_number');
            $table->smallInteger('required_approvals')->default(1);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'approval_rule_id', 'level_number'], 'approval_rule_levels_unique');
        });
        DB::statement('alter table approval_rule_levels add constraint approval_rule_levels_values_check check (
            level_number between 1 and 10 and required_approvals between 1 and 10)');
        TenantSchema::enableRowLevelSecurity('approval_rule_levels', 'required');

        Schema::create('approval_rule_level_approvers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'approval_rule_level_id', 'approval_rule_levels', cascade: true);
            TenantSchema::reference($table, 'membership_id', 'memberships');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'approval_rule_level_id', 'membership_id'], 'approval_level_approvers_unique');
        });
        TenantSchema::enableRowLevelSecurity('approval_rule_level_approvers', 'required');

        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'delegator_membership_id', 'memberships');
            TenantSchema::reference($table, 'delegate_membership_id', 'memberships');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->text('reason');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'delegate_membership_id', 'valid_until'], 'approval_delegations_delegate_index');
        });
        DB::statement('alter table approval_delegations add constraint approval_delegations_values_check check (
            delegator_membership_id <> delegate_membership_id and valid_until > valid_from)');
        TenantSchema::enableRowLevelSecurity('approval_delegations', 'required');

        Schema::table('orders', function (Blueprint $table): void {
            $table->uuid('approval_rule_id')->nullable();
            $table->string('approval_rule_name', 120)->nullable();
            $table->char('approval_fingerprint', 64)->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->text('confirmation_issue')->nullable();
        });

        Schema::create('order_approval_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'order_id', 'orders');
            $table->smallInteger('level_number');
            $table->smallInteger('required_approvals');
            $table->jsonb('approver_membership_ids');
            $table->string('status', 10)->default('pending');
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_id', 'level_number'], 'order_approval_steps_unique');
        });
        DB::statement("alter table order_approval_steps add constraint order_approval_steps_values_check check (
            status in ('pending', 'approved', 'rejected') and required_approvals between 1 and 10
            and jsonb_typeof(approver_membership_ids) = 'array')");
        TenantSchema::enableRowLevelSecurity('order_approval_steps', 'required');

        Schema::create('order_approval_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'order_id', 'orders');
            TenantSchema::reference($table, 'order_approval_step_id', 'order_approval_steps');
            TenantSchema::reference($table, 'actor_membership_id', 'memberships');
            TenantSchema::reference($table, 'principal_membership_id', 'memberships');
            $table->string('decision', 10);
            $table->text('comment')->nullable();
            $table->uuid('delegation_id')->nullable();
            $table->timestampTz('invalidated_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'order_id', 'created_at'], 'order_approval_decisions_order_index');
        });
        DB::statement("alter table order_approval_decisions add constraint order_approval_decisions_decision_check check (decision in ('approved', 'rejected'))");
        // Uma decisão válida por pessoa (e por titular, na delegação) em cada nível.
        DB::statement('create unique index order_approval_decisions_one_actor on order_approval_decisions
            (tenant_id, order_approval_step_id, actor_membership_id) where invalidated_at is null');
        DB::statement('create unique index order_approval_decisions_one_principal on order_approval_decisions
            (tenant_id, order_approval_step_id, principal_membership_id) where invalidated_at is null');
        TenantSchema::enableRowLevelSecurity('order_approval_decisions', 'required');
        // Histórico: apenas inserção e marcação de invalidação.
        DB::statement(sprintf('revoke update, delete, truncate on order_approval_decisions from %s', TenantSchema::appRole()));
        DB::statement(sprintf('grant update (invalidated_at) on order_approval_decisions to %s', TenantSchema::appRole()));

        Schema::create('cost_center_budgets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'cost_center_id', 'cost_centers');
            $table->date('period_start');
            $table->bigInteger('amount_cents');
            $table->bigInteger('reserved_cents')->default(0);
            $table->bigInteger('consumed_cents')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'cost_center_id', 'period_start'], 'cost_center_budgets_unique');
        });
        DB::statement("alter table cost_center_budgets add constraint cost_center_budgets_values_check check (
            amount_cents >= 0 and reserved_cents >= 0 and consumed_cents >= 0
            and reserved_cents + consumed_cents <= amount_cents
            and period_start = date_trunc('month', period_start)::date)");
        TenantSchema::enableRowLevelSecurity('cost_center_budgets', 'required');
        // Valor por Action; saldos apenas por movimentos.
        TenantSchema::projection('cost_center_budgets', ['amount_cents', 'updated_at']);
        DB::statement(sprintf(
            'grant insert (id, tenant_id, buyer_company_id, cost_center_id, period_start, amount_cents, created_at, updated_at) on cost_center_budgets to %s',
            TenantSchema::appRole(),
        ));

        Schema::create('budget_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'cost_center_budget_id', 'cost_center_budgets');
            TenantSchema::reference($table, 'order_id', 'orders');
            $table->string('type', 12);
            $table->bigInteger('reserved_delta');
            $table->bigInteger('consumed_delta');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'order_id'], 'budget_movements_order_index');
        });
        DB::statement("alter table budget_movements add constraint budget_movements_type_check check (case type
            when 'reservation' then reserved_delta > 0 and consumed_delta = 0
            when 'release' then reserved_delta < 0 and consumed_delta = 0
            when 'consumption' then reserved_delta <= 0 and consumed_delta > 0
            else false end)");
        TenantSchema::enableRowLevelSecurity('budget_movements', 'required');
        TenantSchema::appendOnly('budget_movements');

        DB::unprepared(<<<'SQL'
            create or replace function budget_apply_movement() returns trigger
                language plpgsql security definer set search_path = public
                as $$
                begin
                    update cost_center_budgets
                       set reserved_cents = reserved_cents + new.reserved_delta,
                           consumed_cents = consumed_cents + new.consumed_delta,
                           updated_at = now()
                     where id = new.cost_center_budget_id and tenant_id = new.tenant_id;

                    if not found then
                        raise exception 'orçamento inexistente para o movimento %', new.id;
                    end if;

                    return null;
                end
                $$;

            revoke all on function budget_apply_movement() from public;

            create trigger budget_movements_apply after insert on budget_movements
                for each row execute function budget_apply_movement();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists budget_movements_apply on budget_movements; drop function if exists budget_apply_movement();');
        Schema::dropIfExists('budget_movements');
        Schema::dropIfExists('cost_center_budgets');
        Schema::dropIfExists('order_approval_decisions');
        Schema::dropIfExists('order_approval_steps');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['approval_rule_id', 'approval_rule_name', 'approval_fingerprint', 'submitted_at', 'approved_at', 'confirmation_issue']);
        });
        Schema::dropIfExists('approval_delegations');
        Schema::dropIfExists('approval_rule_level_approvers');
        Schema::dropIfExists('approval_rule_levels');
        Schema::dropIfExists('approval_rules');
    }
};
