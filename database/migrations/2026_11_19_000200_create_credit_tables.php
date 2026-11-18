<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crédito comercial (condição de venda a prazo do distribuidor; não é
 * concessão bancária). Fórmula única (RN-080):
 *
 *   exposição  = committed (pedidos confirmados não faturados)
 *              + receivable (faturas em aberto)
 *   disponível = limite + exceções vigentes − exposição
 *
 * committed e receivable só mudam por credit_movements (append-only), via
 * trigger na mesma transação. Faturamento transfere committed → receivable
 * em um único movimento (deltas opostos), sem dupla contagem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            $table->bigInteger('limit_cents')->default(0);
            $table->bigInteger('committed_cents')->default(0);
            $table->bigInteger('receivable_cents')->default(0);
            $table->string('status', 10)->default('active');
            $table->text('blocked_reason')->nullable();
            $table->integer('overdue_tolerance_days')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'buyer_company_id']);
        });
        DB::statement('alter table credit_accounts add constraint credit_accounts_values_check check (
            limit_cents >= 0 and committed_cents >= 0 and receivable_cents >= 0 and overdue_tolerance_days between 0 and 365)');
        DB::statement("alter table credit_accounts add constraint credit_accounts_status_check check (status in ('active', 'blocked'))");
        DB::statement("alter table credit_accounts add constraint credit_accounts_blocked_reason_check check ((status = 'blocked') = (blocked_reason is not null))");
        DB::statement('alter table credit_accounts add constraint credit_accounts_buyer_unique unique (tenant_id, id, buyer_company_id)');
        TenantSchema::enableRowLevelSecurity('credit_accounts', 'required');
        TenantSchema::buyerReadOnly('credit_accounts');
        // Saldos só por movimentos; limite e situação por Action com permissão.
        TenantSchema::projection('credit_accounts', ['limit_cents', 'status', 'blocked_reason', 'overdue_tolerance_days', 'updated_at']);

        Schema::create('credit_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'credit_account_id', 'credit_accounts');
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            $table->string('type', 30);
            $table->bigInteger('committed_delta');
            $table->bigInteger('receivable_delta');
            $table->string('reference_type', 40);
            $table->uuid('reference_id');
            $table->text('reason')->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('correlation_id', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'credit_account_id', 'created_at']);
            $table->index(['tenant_id', 'reference_type', 'reference_id']);
        });
        DB::statement("alter table credit_movements add constraint credit_movements_type_check check (case type
            when 'order_commitment' then committed_delta > 0 and receivable_delta = 0
            when 'commitment_release' then committed_delta < 0 and receivable_delta = 0
            when 'invoicing' then committed_delta <= 0 and receivable_delta > 0
            when 'payment_settlement' then committed_delta = 0 and receivable_delta < 0
            when 'credit_note' then committed_delta = 0 and receivable_delta < 0
            when 'receivable_adjustment' then committed_delta = 0 and receivable_delta <> 0
            else false end)");
        // Conta e empresa do movimento precisam coincidir.
        DB::statement('alter table credit_movements add constraint credit_movements_account_buyer_fk
            foreign key (tenant_id, credit_account_id, buyer_company_id) references credit_accounts (tenant_id, id, buyer_company_id)');
        TenantSchema::enableRowLevelSecurity('credit_movements', 'required');
        TenantSchema::buyerReadOnly('credit_movements');
        TenantSchema::appendOnly('credit_movements');

        DB::unprepared(<<<'SQL'
            create or replace function credit_apply_movement() returns trigger
                language plpgsql security definer set search_path = public
                as $$
                begin
                    update credit_accounts
                       set committed_cents = committed_cents + new.committed_delta,
                           receivable_cents = receivable_cents + new.receivable_delta,
                           updated_at = now()
                     where id = new.credit_account_id and tenant_id = new.tenant_id;

                    if not found then
                        raise exception 'conta de crédito inexistente para o movimento %', new.id;
                    end if;

                    return null;
                end
                $$;

            revoke all on function credit_apply_movement() from public;

            create trigger credit_movements_apply after insert on credit_movements
                for each row execute function credit_apply_movement();
            SQL);

        // Exceções temporárias de limite, com justificativa e responsável.
        Schema::create('credit_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'credit_account_id', 'credit_accounts');
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            $table->bigInteger('amount_cents');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->text('reason');
            $table->foreignUuid('granted_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement('alter table credit_exceptions add constraint credit_exceptions_values_check check (amount_cents > 0 and valid_until > valid_from)');
        DB::statement('alter table credit_exceptions add constraint credit_exceptions_account_buyer_fk
            foreign key (tenant_id, credit_account_id, buyer_company_id) references credit_accounts (tenant_id, id, buyer_company_id)');
        TenantSchema::enableRowLevelSecurity('credit_exceptions', 'required');
        TenantSchema::buyerReadOnly('credit_exceptions');

        // Histórico de alterações de limite e situação.
        Schema::create('credit_limit_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'credit_account_id', 'credit_accounts');
            $table->bigInteger('previous_limit_cents');
            $table->bigInteger('new_limit_cents');
            $table->string('previous_status', 10);
            $table->string('new_status', 10);
            $table->text('reason');
            $table->foreignUuid('changed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        TenantSchema::enableRowLevelSecurity('credit_limit_changes');
        TenantSchema::buyerReadOnly('credit_limit_changes');
        TenantSchema::appendOnly('credit_limit_changes');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_limit_changes');
        Schema::dropIfExists('credit_exceptions');
        DB::unprepared('drop trigger if exists credit_movements_apply on credit_movements; drop function if exists credit_apply_movement();');
        Schema::dropIfExists('credit_movements');
        Schema::dropIfExists('credit_accounts');
    }
};
