<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro detalhado das empresas compradoras: unidades, endereços,
 * centros de custo, contatos e condições de pagamento permitidas.
 * Todas as tabelas de empresa usam RLS restritiva por empresa compradora e
 * são somente leitura no contexto de comprador (cadastro mantido pela equipe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buyer_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', cascade: true);
            $table->string('code', 20);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'buyer_company_id', 'code']);
        });
        TenantSchema::enableRowLevelSecurity('buyer_units', 'required');
        TenantSchema::buyerReadOnly('buyer_units');

        Schema::create('buyer_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', cascade: true);
            TenantSchema::reference($table, 'buyer_unit_id', 'buyer_units', nullable: true);
            $table->string('kind', 10);
            $table->string('label', 60);
            $table->string('recipient', 120)->nullable();
            $table->string('street', 160);
            $table->string('number', 20);
            $table->string('complement', 80)->nullable();
            $table->string('district', 80);
            $table->string('city', 120);
            $table->char('state', 2);
            $table->char('postal_code', 8);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("alter table buyer_addresses add constraint buyer_addresses_kind_check check (kind in ('billing', 'shipping'))");
        DB::statement("alter table buyer_addresses add constraint buyer_addresses_postal_code_check check (postal_code ~ '^[0-9]{8}$')");
        DB::statement('create unique index buyer_addresses_one_default on buyer_addresses (tenant_id, buyer_company_id, kind) where is_default and is_active');
        TenantSchema::enableRowLevelSecurity('buyer_addresses', 'required');
        TenantSchema::buyerReadOnly('buyer_addresses');

        Schema::create('cost_centers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', cascade: true);
            TenantSchema::reference($table, 'buyer_unit_id', 'buyer_units', nullable: true);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'buyer_company_id', 'code']);
        });
        TenantSchema::enableRowLevelSecurity('cost_centers', 'required');
        TenantSchema::buyerReadOnly('cost_centers');

        Schema::create('buyer_contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', cascade: true);
            $table->string('name', 160);
            $table->string('email', 254)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('position', 80)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
        });
        TenantSchema::enableRowLevelSecurity('buyer_contacts', 'required');
        TenantSchema::buyerReadOnly('buyer_contacts');

        // Condições de pagamento do distribuidor. installment_days: dias de
        // vencimento de cada parcela a partir do faturamento ([0] = à vista).
        Schema::create('payment_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('code', 20);
            $table->string('name', 80);
            $table->jsonb('installment_days');
            $table->boolean('requires_credit');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("alter table payment_terms add constraint payment_terms_installments_check check (jsonb_typeof(installment_days) = 'array' and jsonb_array_length(installment_days) between 1 and 12)");
        TenantSchema::enableRowLevelSecurity('payment_terms');
        TenantSchema::buyerReadOnly('payment_terms');

        Schema::create('buyer_payment_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', cascade: true);
            TenantSchema::reference($table, 'payment_term_id', 'payment_terms');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'buyer_company_id', 'payment_term_id']);
        });
        DB::statement('create unique index buyer_payment_terms_one_default on buyer_payment_terms (tenant_id, buyer_company_id) where is_default');
        TenantSchema::enableRowLevelSecurity('buyer_payment_terms', 'required');
        TenantSchema::buyerReadOnly('buyer_payment_terms');
    }

    public function down(): void
    {
        Schema::dropIfExists('buyer_payment_terms');
        Schema::dropIfExists('payment_terms');
        Schema::dropIfExists('buyer_contacts');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('buyer_addresses');
        Schema::dropIfExists('buyer_units');
    }
};
