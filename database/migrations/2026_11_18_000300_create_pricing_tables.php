<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Preços e condições comerciais (ADR-010, ADR-011).
 *
 * - price_lists: padrão, por grupo ou por empresa, com vigência e prioridade;
 * - price_list_items / price_tiers: preço por SKU e faixas por volume;
 * - negotiated_prices: condição contratada por empresa e SKU, sem
 *   sobreposição de vigência (exclusion constraint);
 * - pricing_settings: versão de preços (chave do cache), legível pelo
 *   comprador e somente leitura nesse contexto;
 * - pricing_policies: margem mínima padrão, dado interno invisível ao
 *   comprador (internal_only).
 *
 * O comprador enxerga apenas as tabelas aplicáveis a ele (padrão, do seu
 * grupo ou da sua empresa) e as próprias condições negociadas; itens e
 * faixas herdam a visibilidade das tabelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_settings', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->bigInteger('price_version')->default(1);
            $table->timestampTz('updated_at')->useCurrent();
        });
        self::tenantKeyedTable('pricing_settings');
        TenantSchema::buyerReadOnly('pricing_settings');

        Schema::create('pricing_policies', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->integer('min_margin_bps')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement('alter table pricing_policies add constraint pricing_policies_margin_check check (min_margin_bps between -10000 and 10000)');
        self::tenantKeyedTable('pricing_policies');
        DB::statement('create policy internal_only on pricing_policies as restrictive for all
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())');

        Schema::create('price_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('code', 30);
            $table->string('name', 120);
            $table->string('scope', 10);
            TenantSchema::reference($table, 'customer_group_id', 'customer_groups', nullable: true);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies', nullable: true);
            $table->integer('priority')->default(0);
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 10)->default('draft');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("alter table price_lists add constraint price_lists_scope_check check (
            (scope = 'default' and customer_group_id is null and buyer_company_id is null)
            or (scope = 'group' and customer_group_id is not null and buyer_company_id is null)
            or (scope = 'customer' and buyer_company_id is not null and customer_group_id is null))");
        DB::statement("alter table price_lists add constraint price_lists_status_check check (status in ('draft', 'active', 'archived'))");
        DB::statement('alter table price_lists add constraint price_lists_validity_check check (valid_until is null or valid_until >= valid_from)');
        TenantSchema::enableRowLevelSecurity('price_lists');
        TenantSchema::buyerReadOnly('price_lists');
        DB::statement("create policy buyer_visibility on price_lists as restrictive for select
            using (app_sees_all_buyers() or (status = 'active' and (
                scope = 'default'
                or (scope = 'customer' and buyer_company_id = app_current_buyer())
                or (scope = 'group' and customer_group_id = app_buyer_group()))))");

        Schema::create('price_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'price_list_id', 'price_lists', cascade: true);
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->bigInteger('unit_price_cents');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'price_list_id', 'sku_id']);
        });
        DB::statement('alter table price_list_items add constraint price_list_items_price_check check (unit_price_cents > 0)');
        TenantSchema::enableRowLevelSecurity('price_list_items');
        TenantSchema::buyerReadOnly('price_list_items');
        // O item herda a visibilidade da tabela e do SKU (produto restrito
        // fora do catálogo do comprador não revela preço).
        DB::statement('create policy buyer_visibility on price_list_items as restrictive for select
            using (app_sees_all_buyers() or (
                exists (select 1 from price_lists pl where pl.id = price_list_id)
                and exists (select 1 from skus s where s.id = sku_id)))');

        Schema::create('price_tiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'price_list_item_id', 'price_list_items', cascade: true);
            $table->decimal('min_quantity', 18, 3);
            $table->bigInteger('unit_price_cents');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'price_list_item_id', 'min_quantity']);
        });
        DB::statement('alter table price_tiers add constraint price_tiers_values_check check (min_quantity > 0 and unit_price_cents > 0)');
        TenantSchema::enableRowLevelSecurity('price_tiers');
        TenantSchema::buyerReadOnly('price_tiers');
        DB::statement('create policy buyer_visibility on price_tiers as restrictive for select
            using (app_sees_all_buyers() or exists (select 1 from price_list_items pli where pli.id = price_list_item_id))');

        Schema::create('negotiated_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->bigInteger('unit_price_cents');
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('source', 10)->default('manual');
            $table->uuid('source_reference_id')->nullable();
            $table->string('status', 10)->default('active');
            $table->foreignUuid('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table negotiated_prices add constraint negotiated_prices_source_check check (source in ('manual', 'quote'))");
        DB::statement("alter table negotiated_prices add constraint negotiated_prices_status_check check (status in ('active', 'revoked'))");
        DB::statement('alter table negotiated_prices add constraint negotiated_prices_values_check check (unit_price_cents > 0 and (valid_until is null or valid_until >= valid_from))');
        // Duas condições ativas para a mesma empresa e SKU não podem ter vigências sobrepostas.
        DB::statement("alter table negotiated_prices add constraint negotiated_prices_no_overlap exclude using gist (
            tenant_id with =, buyer_company_id with =, sku_id with =,
            daterange(valid_from, coalesce(valid_until, 'infinity'::date), '[]') with &&
        ) where (status = 'active')");
        TenantSchema::enableRowLevelSecurity('negotiated_prices', 'required');
        TenantSchema::buyerReadOnly('negotiated_prices');
        DB::statement('create policy buyer_visibility on negotiated_prices as restrictive for select
            using (app_sees_all_buyers() or exists (select 1 from skus s where s.id = sku_id))');
    }

    public function down(): void
    {
        Schema::dropIfExists('negotiated_prices');
        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('price_list_items');
        Schema::dropIfExists('price_lists');
        Schema::dropIfExists('pricing_policies');
        Schema::dropIfExists('pricing_settings');
    }

    /**
     * Tabela com chave tenant_id (uma linha por tenant): RLS forçada com
     * isolamento pelo próprio tenant_id.
     */
    private static function tenantKeyedTable(string $table): void
    {
        DB::statement("alter table {$table} enable row level security");
        DB::statement("alter table {$table} force row level security");
        DB::statement("create policy tenant_isolation on {$table} as permissive for all
            using (tenant_id = app_current_tenant()) with check (tenant_id = app_current_tenant())");
    }
};
