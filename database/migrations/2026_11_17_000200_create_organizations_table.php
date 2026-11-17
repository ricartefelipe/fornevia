<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * organizations: registro de tenants (distribuidores contratantes).
 * Não contém dados comerciais; fica fora da RLS para permitir a seleção
 * de contexto e o roteamento de webhooks. branches (filiais) é a primeira
 * tabela isolada por tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('legal_name', 200);
            $table->string('trade_name', 160);
            $table->string('document', 14)->unique();
            $table->addColumn('citext', 'slug')->unique();
            $table->string('status', 20)->default('trial');
            $table->string('email', 254)->nullable();
            $table->string('phone', 20)->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
        });

        DB::statement("alter table organizations add constraint organizations_status_check
            check (status in ('trial', 'active', 'past_due', 'suspended', 'cancelled'))");
        DB::statement("alter table organizations add constraint organizations_document_check
            check (document ~ '^[0-9]{14}$')");

        Schema::create('branches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('code', 20);
            $table->string('name', 160);
            $table->string('document', 14)->nullable();
            $table->string('city', 120)->nullable();
            $table->char('state', 2)->nullable();
            $table->boolean('is_headquarters')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        DB::statement('create unique index branches_one_headquarters on branches (tenant_id) where is_headquarters');
        TenantSchema::enableRowLevelSecurity('branches');
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('organizations');
    }
};
