<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo do distribuidor.
 *
 * Confidencialidade imposta pelo banco:
 * - custos (sku_costs) e políticas comerciais internas (sku_commercial_policies)
 *   só são legíveis em contextos de equipe, integração ou sistema;
 * - produtos restritos só aparecem ao comprador com catálogo privado
 *   atribuído à empresa ou ao seu grupo; SKUs e mídias herdam a visibilidade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('code', 10);
            $table->string('name', 40);
            $table->smallInteger('decimals')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('alter table units_of_measure add constraint units_of_measure_decimals_check check (decimals between 0 and 3)');
        TenantSchema::enableRowLevelSecurity('units_of_measure');

        Schema::create('categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'parent_id', 'categories', nullable: true);
            $table->string('name', 120);
            $table->string('slug', 140);
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'slug']);
        });
        DB::statement('alter table categories add constraint categories_not_self_parent check (parent_id is null or parent_id <> id)');
        TenantSchema::enableRowLevelSecurity('categories');

        Schema::create('products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'category_id', 'categories', nullable: true);
            TenantSchema::reference($table, 'base_unit_id', 'units_of_measure');
            $table->string('name', 200);
            $table->string('slug', 220);
            $table->string('brand', 80)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 10)->default('draft');
            $table->string('visibility', 12)->default('public');
            $table->boolean('allow_backorder')->default(false);
            $table->integer('backorder_lead_days')->nullable();
            $table->string('ncm', 8)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'slug']);
        });
        DB::statement("alter table products add constraint products_status_check check (status in ('draft', 'active', 'inactive', 'archived'))");
        DB::statement("alter table products add constraint products_visibility_check check (visibility in ('public', 'restricted'))");
        DB::statement("alter table products add constraint products_archived_check check ((status = 'archived') = (archived_at is not null))");
        DB::statement('alter table products add constraint products_backorder_check check (allow_backorder or backorder_lead_days is null)');
        TenantSchema::enableRowLevelSecurity('products');

        Schema::create('skus', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'product_id', 'products');
            TenantSchema::reference($table, 'sales_unit_id', 'units_of_measure');
            $table->string('code', 40);
            $table->string('gtin', 14)->nullable();
            $table->string('variant_label', 120)->nullable();
            $table->jsonb('attributes')->default('{}');
            // Unidades base contidas em uma unidade de venda (ex.: caixa = 12 UN).
            $table->decimal('conversion_factor', 18, 6)->default(1);
            $table->string('package_label', 60)->nullable();
            $table->decimal('min_order_quantity', 18, 3)->default(1);
            $table->decimal('order_multiple', 18, 3)->default(1);
            $table->integer('weight_grams')->nullable();
            $table->string('status', 10)->default('active');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("alter table skus add constraint skus_status_check check (status in ('active', 'inactive', 'archived'))");
        DB::statement('alter table skus add constraint skus_quantities_check check (conversion_factor > 0 and min_order_quantity > 0 and order_multiple > 0)');
        DB::statement("alter table skus add constraint skus_gtin_check check (gtin is null or gtin ~ '^[0-9]{8,14}$')");
        DB::statement("alter table skus add constraint skus_attributes_object check (jsonb_typeof(attributes) = 'object')");
        TenantSchema::enableRowLevelSecurity('skus');

        // Custos históricos (append-only). O custo vigente é o mais recente
        // com effective_from <= agora; pedidos guardam o custo no snapshot.
        Schema::create('sku_costs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->bigInteger('cost_cents');
            $table->timestampTz('effective_from');
            $table->foreignUuid('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'sku_id', 'effective_from']);
        });
        DB::statement('alter table sku_costs add constraint sku_costs_non_negative check (cost_cents >= 0)');
        TenantSchema::enableRowLevelSecurity('sku_costs');
        TenantSchema::appendOnly('sku_costs');

        // Política comercial interna por SKU: preço mínimo e margem mínima.
        Schema::create('sku_commercial_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'sku_id', 'skus', cascade: true);
            $table->bigInteger('min_unit_price_cents')->nullable();
            $table->integer('min_margin_bps')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'sku_id']);
        });
        DB::statement('alter table sku_commercial_policies add constraint sku_commercial_policies_values_check check (coalesce(min_unit_price_cents, 0) >= 0 and coalesce(min_margin_bps, 0) between -10000 and 10000)');
        TenantSchema::enableRowLevelSecurity('sku_commercial_policies');

        foreach (['sku_costs', 'sku_commercial_policies'] as $internal) {
            DB::statement("create policy internal_only on {$internal} as restrictive for all
                using (app_sees_all_buyers()) with check (app_sees_all_buyers())");
        }

        Schema::create('private_catalogs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        TenantSchema::enableRowLevelSecurity('private_catalogs');

        Schema::create('private_catalog_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'private_catalog_id', 'private_catalogs', cascade: true);
            TenantSchema::reference($table, 'product_id', 'products', cascade: true);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'private_catalog_id', 'product_id']);
        });
        TenantSchema::enableRowLevelSecurity('private_catalog_products');

        Schema::create('private_catalog_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'private_catalog_id', 'private_catalogs', cascade: true);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', nullable: true, cascade: true);
            TenantSchema::reference($table, 'customer_group_id', 'customer_groups', nullable: true, cascade: true);
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::statement('alter table private_catalog_assignments add constraint private_catalog_assignments_target_check check (num_nonnulls(buyer_company_id, customer_group_id) = 1)');
        DB::statement('create unique index private_catalog_assignments_unique on private_catalog_assignments (tenant_id, private_catalog_id, coalesce(buyer_company_id, customer_group_id))');
        TenantSchema::enableRowLevelSecurity('private_catalog_assignments', 'nullable');

        // Visibilidade para o comprador, verificada no próprio banco.
        DB::unprepared(<<<'SQL'
            create or replace function app_buyer_group() returns uuid
                language sql stable
                as $$ select bc.customer_group_id from buyer_companies bc where bc.id = app_current_buyer() $$;

            create or replace function app_buyer_sees_product(p_product_id uuid, p_status text, p_visibility text) returns boolean
                language sql stable
                as $$
                    select p_status = 'active' and (
                        p_visibility = 'public' or exists (
                            select 1
                            from private_catalog_products pcp
                            join private_catalogs pc on pc.id = pcp.private_catalog_id and pc.is_active
                            join private_catalog_assignments pca on pca.private_catalog_id = pc.id
                            where pcp.product_id = p_product_id
                              and (pca.buyer_company_id = app_current_buyer() or pca.customer_group_id = app_buyer_group())
                        )
                    )
                $$;
            SQL);

        DB::statement('create policy buyer_visibility on products as restrictive for all
            using (app_sees_all_buyers() or app_buyer_sees_product(id, status, visibility))
            with check (app_sees_all_buyers())');
        DB::statement("create policy buyer_visibility on skus as restrictive for all
            using (app_sees_all_buyers() or (status = 'active' and exists (select 1 from products p where p.id = product_id)))
            with check (app_sees_all_buyers())");

        Schema::create('product_media', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'product_id', 'products', cascade: true);
            $table->string('kind', 10);
            $table->string('disk', 20);
            $table->string('path', 300);
            $table->string('original_name', 200);
            $table->string('mime_type', 100);
            $table->bigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->boolean('visible_to_buyers')->default(true);
            $table->integer('sort_order')->default(0);
            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("alter table product_media add constraint product_media_kind_check check (kind in ('image', 'document'))");
        DB::statement('alter table product_media add constraint product_media_size_check check (size_bytes > 0)');
        TenantSchema::enableRowLevelSecurity('product_media');
        DB::statement('create policy buyer_visibility on product_media as restrictive for all
            using (app_sees_all_buyers() or (visible_to_buyers and exists (select 1 from products p where p.id = product_id)))
            with check (app_sees_all_buyers())');

        // Catálogos privados, seus produtos e atribuições: o comprador vê apenas
        // os atribuídos à própria empresa ou ao seu grupo (não descobre
        // catálogos exclusivos de outros clientes).
        DB::statement('create policy buyer_visibility on private_catalog_assignments as restrictive for select
            using (app_sees_all_buyers() or buyer_company_id = app_current_buyer() or customer_group_id = app_buyer_group())');
        DB::statement('create policy buyer_visibility on private_catalogs as restrictive for select
            using (app_sees_all_buyers() or exists (
                select 1 from private_catalog_assignments pca where pca.private_catalog_id = private_catalogs.id
            ))');
        DB::statement('create policy buyer_visibility on private_catalog_products as restrictive for select
            using (app_sees_all_buyers() or exists (
                select 1 from private_catalog_assignments pca where pca.private_catalog_id = private_catalog_products.private_catalog_id
            ))');

        // Comprador apenas lê o catálogo; escrita é da equipe, integração ou sistema.
        foreach (['units_of_measure', 'categories', 'products', 'skus', 'private_catalogs', 'private_catalog_products',
            'private_catalog_assignments', 'product_media'] as $managed) {
            TenantSchema::buyerReadOnly($managed);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('private_catalog_assignments');
        Schema::dropIfExists('private_catalog_products');
        Schema::dropIfExists('private_catalogs');
        Schema::dropIfExists('sku_commercial_policies');
        Schema::dropIfExists('sku_costs');
        Schema::dropIfExists('skus');
        Schema::dropIfExists('products');
        DB::unprepared('drop function if exists app_buyer_sees_product(uuid, text, text); drop function if exists app_buyer_group();');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('units_of_measure');
    }
};
