<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identidade e acesso por contexto: papéis, grupos e empresas compradoras
 * (núcleo), associações (memberships), convites e auditoria do tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('key', 40);
            $table->string('name', 80);
            $table->string('kind', 10);
            $table->jsonb('permissions')->default('[]');
            $table->integer('max_discount_bps')->default(0);
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'key']);
        });
        DB::statement("alter table roles add constraint roles_kind_check check (kind in ('staff', 'buyer'))");
        DB::statement('alter table roles add constraint roles_discount_check check (max_discount_bps between 0 and 10000)');
        DB::statement("alter table roles add constraint roles_permissions_array check (jsonb_typeof(permissions) = 'array')");
        TenantSchema::enableRowLevelSecurity('roles');

        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        TenantSchema::enableRowLevelSecurity('customer_groups');

        Schema::create('buyer_companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('legal_name', 200);
            $table->string('trade_name', 160);
            $table->string('document', 14);
            $table->string('state_registration', 20)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 20)->nullable();
            TenantSchema::reference($table, 'customer_group_id', 'customer_groups', nullable: true);
            $table->uuid('sales_rep_membership_id')->nullable();
            $table->string('status', 12)->default('active');
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'document']);
        });
        DB::statement("alter table buyer_companies add constraint buyer_companies_status_check check (status in ('active', 'blocked', 'inactive'))");
        DB::statement("alter table buyer_companies add constraint buyer_companies_document_check check (document ~ '^[0-9]{14}$')");
        // O comprador enxerga apenas o registro da própria empresa.
        TenantSchema::enableRowLevelSecurity('buyer_companies', 'required', 'id');

        Schema::create('memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 10);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', nullable: true);
            TenantSchema::reference($table, 'role_id', 'roles');
            TenantSchema::reference($table, 'branch_id', 'branches', nullable: true);
            $table->string('status', 10)->default('active');
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table memberships add constraint memberships_kind_check check (kind in ('staff', 'buyer'))");
        DB::statement("alter table memberships add constraint memberships_status_check check (status in ('active', 'revoked'))");
        DB::statement("alter table memberships add constraint memberships_buyer_company_check check ((kind = 'buyer') = (buyer_company_id is not null))");
        DB::statement("alter table memberships add constraint memberships_revoked_check check ((status = 'revoked') = (revoked_at is not null))");
        DB::statement('alter table memberships add constraint memberships_unique_context unique nulls not distinct (tenant_id, user_id, kind, buyer_company_id)');
        DB::statement("create index memberships_user_idx on memberships (user_id) where status = 'active'");

        TenantSchema::enableRowLevelSecurity('memberships');
        // O próprio usuário enxerga suas associações em todos os tenants
        // (seleção de contexto); o comprador vê apenas colegas da empresa e
        // o representante responsável por ela.
        DB::statement('create policy own_memberships on memberships as permissive for select using (user_id = app_current_user())');
        DB::statement('create policy buyer_isolation on memberships as restrictive for all
            using (app_sees_all_buyers() or user_id = app_current_user() or buyer_company_id = app_current_buyer()
                   or id = (select bc.sales_rep_membership_id from buyer_companies bc where bc.id = app_current_buyer()))
            with check (app_sees_all_buyers() or buyer_company_id = app_current_buyer())');

        DB::statement('alter table buyer_companies add constraint buyer_companies_sales_rep_fk
            foreign key (tenant_id, sales_rep_membership_id) references memberships (tenant_id, id) on delete restrict');
        DB::statement('create index buyer_companies_sales_rep_idx on buyer_companies (tenant_id, sales_rep_membership_id)');

        Schema::create('invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->addColumn('citext', 'email');
            $table->string('kind', 10);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', nullable: true);
            TenantSchema::reference($table, 'role_id', 'roles');
            TenantSchema::reference($table, 'branch_id', 'branches', nullable: true);
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("alter table invitations add constraint invitations_kind_check check (kind in ('staff', 'buyer'))");
        DB::statement("alter table invitations add constraint invitations_buyer_company_check check ((kind = 'buyer') = (buyer_company_id is not null))");
        DB::statement("create unique index invitations_one_pending on invitations (tenant_id, email, kind, coalesce(buyer_company_id, '00000000-0000-0000-0000-000000000000'::uuid)) where accepted_at is null and revoked_at is null");
        TenantSchema::enableRowLevelSecurity('invitations', 'nullable');

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('actor_membership_id')->nullable();
            $table->uuid('actor_api_client_id')->nullable();
            $table->string('action', 80);
            $table->string('subject_type', 60)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('data')->default('{}');
            $table->text('reason')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement('alter table audit_logs add constraint audit_logs_membership_fk
            foreign key (tenant_id, actor_membership_id) references memberships (tenant_id, id) on delete restrict');
        TenantSchema::enableRowLevelSecurity('audit_logs');
        TenantSchema::appendOnly('audit_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('invitations');
        DB::statement('alter table if exists buyer_companies drop constraint if exists buyer_companies_sales_rep_fk');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('buyer_companies');
        Schema::dropIfExists('customer_groups');
        Schema::dropIfExists('roles');
    }
};
