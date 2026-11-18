<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Carrinho, listas de compra, pedidos, eventos, idempotência e outbox.
 *
 * - itens carregam buyer_company_id e referenciam o documento-pai por FK
 *   composta (tenant_id, documento, empresa): um item nunca pertence a
 *   documento de outra empresa;
 * - preços, descrições e condições ficam em snapshot no pedido;
 * - custo histórico por item em order_item_costs (somente equipe/sistema);
 * - estados separados: comercial, aprovação, logístico e financeiro (ADR-009);
 * - um carrinho gera no máximo um pedido (unique em source_cart_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'membership_id', 'memberships');
            TenantSchema::reference($table, 'buyer_unit_id', 'buyer_units', nullable: true);
            TenantSchema::reference($table, 'cost_center_id', 'cost_centers', nullable: true);
            TenantSchema::reference($table, 'shipping_address_id', 'buyer_addresses', nullable: true);
            TenantSchema::reference($table, 'billing_address_id', 'buyer_addresses', nullable: true);
            TenantSchema::reference($table, 'payment_term_id', 'payment_terms', nullable: true);
            $table->string('status', 10)->default('open');
            $table->string('purchase_order_reference', 60)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('converted_order_id')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table carts add constraint carts_status_check check (status in ('open', 'converted', 'abandoned'))");
        DB::statement("create unique index carts_one_open_per_membership on carts (tenant_id, membership_id) where status = 'open'");
        DB::statement('alter table carts add constraint carts_buyer_unique unique (tenant_id, id, buyer_company_id)');
        TenantSchema::enableRowLevelSecurity('carts', 'required');

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->uuid('cart_id');
            $table->uuid('buyer_company_id');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->decimal('quantity', 18, 3);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'cart_id', 'sku_id']);
        });
        DB::statement('alter table cart_items add constraint cart_items_cart_fk foreign key (tenant_id, cart_id, buyer_company_id)
            references carts (tenant_id, id, buyer_company_id) on delete cascade');
        DB::statement('alter table cart_items add constraint cart_items_quantity_check check (quantity > 0)');
        TenantSchema::enableRowLevelSecurity('cart_items', 'required');

        Schema::create('shopping_lists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'membership_id', 'memberships');
            $table->string('name', 120);
            $table->boolean('is_shared')->default(false);
            $table->timestampsTz();
        });
        DB::statement('alter table shopping_lists add constraint shopping_lists_buyer_unique unique (tenant_id, id, buyer_company_id)');
        TenantSchema::enableRowLevelSecurity('shopping_lists', 'required');

        Schema::create('shopping_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->uuid('shopping_list_id');
            $table->uuid('buyer_company_id');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->decimal('quantity', 18, 3);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'shopping_list_id', 'sku_id']);
        });
        DB::statement('alter table shopping_list_items add constraint shopping_list_items_list_fk foreign key (tenant_id, shopping_list_id, buyer_company_id)
            references shopping_lists (tenant_id, id, buyer_company_id) on delete cascade');
        DB::statement('alter table shopping_list_items add constraint shopping_list_items_quantity_check check (quantity > 0)');
        TenantSchema::enableRowLevelSecurity('shopping_list_items', 'required');

        DB::statement('create sequence order_number_seq as bigint start 1');
        $app = TenantSchema::appRole();
        DB::statement("grant usage on sequence order_number_seq to {$app}");

        Schema::create('orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('number', 20);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::reference($table, 'buyer_unit_id', 'buyer_units', nullable: true);
            TenantSchema::reference($table, 'cost_center_id', 'cost_centers', nullable: true);
            TenantSchema::reference($table, 'payment_term_id', 'payment_terms');
            $table->foreignUuid('placed_by_user_id')->constrained('users')->restrictOnDelete();
            TenantSchema::reference($table, 'placed_by_membership_id', 'memberships', nullable: true);
            TenantSchema::reference($table, 'sales_rep_membership_id', 'memberships', nullable: true);
            $table->string('source', 10);
            $table->uuid('source_cart_id')->nullable();
            $table->uuid('source_quote_version_id')->nullable();
            $table->string('purchase_order_reference', 60)->nullable();
            $table->jsonb('shipping_address');
            $table->jsonb('billing_address')->nullable();
            $table->jsonb('payment_term');
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('discount_cents');
            $table->bigInteger('freight_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->bigInteger('credit_committed_cents')->default(0);
            $table->string('commercial_status', 20);
            $table->string('approval_status', 12)->default('not_required');
            $table->string('fulfillment_status', 20)->default('pending');
            $table->string('financial_status', 20)->default('not_invoiced');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'buyer_company_id', 'created_at']);
        });
        DB::statement('create unique index orders_one_per_cart on orders (tenant_id, source_cart_id) where source_cart_id is not null');
        DB::statement('create unique index orders_one_per_quote_version on orders (tenant_id, source_quote_version_id) where source_quote_version_id is not null');
        DB::statement('alter table orders add constraint orders_buyer_unique unique (tenant_id, id, buyer_company_id)');
        DB::statement("alter table orders add constraint orders_source_check check (source in ('cart', 'reorder', 'quote', 'api', 'staff'))");
        DB::statement("alter table orders add constraint orders_commercial_status_check check (commercial_status in
            ('pending_approval', 'approved', 'confirmed', 'partially_cancelled', 'cancelled', 'completed', 'rejected'))");
        DB::statement("alter table orders add constraint orders_approval_status_check check (approval_status in ('not_required', 'pending', 'approved', 'rejected'))");
        DB::statement("alter table orders add constraint orders_fulfillment_status_check check (fulfillment_status in
            ('pending', 'partially_shipped', 'shipped', 'partially_delivered', 'delivered'))");
        DB::statement("alter table orders add constraint orders_financial_status_check check (financial_status in
            ('not_invoiced', 'partially_invoiced', 'invoiced', 'partially_paid', 'paid'))");
        DB::statement('alter table orders add constraint orders_totals_check check (
            subtotal_cents >= 0 and discount_cents >= 0 and freight_cents >= 0
            and total_cents = subtotal_cents - discount_cents + freight_cents
            and credit_committed_cents between 0 and total_cents)');
        // A sequência pertence à coluna: removida junto com a tabela (inclusive em migrate:fresh).
        DB::statement('alter sequence order_number_seq owned by orders.number');
        TenantSchema::enableRowLevelSecurity('orders', 'required');

        Schema::create('order_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->uuid('order_id');
            $table->uuid('buyer_company_id');
            $table->integer('line_number');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->string('sku_code', 40);
            $table->string('product_name', 200);
            $table->string('variant_label', 120)->nullable();
            $table->string('unit_code', 10);
            $table->string('package_label', 60)->nullable();
            $table->decimal('conversion_factor', 18, 6);
            $table->decimal('quantity', 18, 3);
            $table->decimal('base_quantity', 18, 3);
            $table->bigInteger('unit_price_cents');
            $table->integer('discount_bps')->default(0);
            $table->bigInteger('unit_net_price_cents');
            $table->bigInteger('line_gross_cents');
            $table->bigInteger('line_total_cents');
            $table->jsonb('price_source');
            $table->decimal('quantity_reserved', 18, 3)->default(0);
            $table->decimal('quantity_backordered', 18, 3)->default(0);
            $table->decimal('quantity_cancelled', 18, 3)->default(0);
            $table->decimal('quantity_shipped', 18, 3)->default(0);
            $table->decimal('quantity_delivered', 18, 3)->default(0);
            $table->decimal('quantity_returned', 18, 3)->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'order_id', 'line_number']);
        });
        DB::statement('alter table order_items add constraint order_items_order_fk foreign key (tenant_id, order_id, buyer_company_id)
            references orders (tenant_id, id, buyer_company_id) on delete restrict');
        DB::statement('alter table order_items add constraint order_items_quantities_check check (
            quantity > 0 and base_quantity > 0
            and quantity_cancelled >= 0 and quantity_shipped >= 0 and quantity_delivered >= 0 and quantity_returned >= 0
            and quantity_reserved >= 0 and quantity_backordered >= 0
            and quantity_cancelled + quantity_shipped <= quantity
            and quantity_delivered <= quantity_shipped
            and quantity_returned <= quantity_shipped)');
        DB::statement('alter table order_items add constraint order_items_amounts_check check (
            unit_price_cents > 0 and unit_net_price_cents > 0 and line_total_cents >= 0 and line_gross_cents >= line_total_cents
            and discount_bps between 0 and 10000)');
        TenantSchema::enableRowLevelSecurity('order_items', 'required');

        DB::statement('alter table stock_reservations add constraint stock_reservations_order_item_fk
            foreign key (tenant_id, order_item_id) references order_items (tenant_id, id) on delete restrict');

        Schema::create('order_item_costs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'order_item_id', 'order_items');
            $table->bigInteger('unit_cost_cents')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'order_item_id']);
        });
        TenantSchema::enableRowLevelSecurity('order_item_costs');
        DB::statement('create policy internal_only on order_item_costs as restrictive for all
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())');
        TenantSchema::appendOnly('order_item_costs');

        Schema::create('order_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->uuid('order_id');
            $table->uuid('buyer_company_id');
            $table->string('type', 60);
            $table->jsonb('data')->default('{}');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('correlation_id', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'order_id', 'created_at']);
        });
        DB::statement('alter table order_events add constraint order_events_order_fk foreign key (tenant_id, order_id, buyer_company_id)
            references orders (tenant_id, id, buyer_company_id) on delete restrict');
        TenantSchema::enableRowLevelSecurity('order_events', 'required');
        TenantSchema::appendOnly('order_events');

        // Idempotência persistida (ADR-007): chave por tenant, escopo e ator.
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('scope', 60);
            $table->string('actor', 64);
            $table->string('key', 100);
            $table->char('fingerprint', 64);
            $table->string('resource_type', 40)->nullable();
            $table->uuid('resource_id')->nullable();
            $table->jsonb('response')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('expires_at');

            $table->unique(['tenant_id', 'scope', 'actor', 'key']);
            $table->index(['expires_at']);
        });
        TenantSchema::enableRowLevelSecurity('idempotency_keys');
        // A resposta armazenada pode conter dados do pedido: cada ator só
        // enxerga as próprias chaves (equipe e sistema enxergam todas).
        DB::statement('create policy own_keys on idempotency_keys as restrictive for all
            using (app_sees_all_buyers() or actor = app_current_user()::text)
            with check (app_sees_all_buyers() or actor = app_current_user()::text)');

        // Transactional Outbox (ADR-008). A publicação (relay) é feita pelo
        // papel fornevia_relay, com acesso apenas a estas tabelas.
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('type', 80);
            $table->smallInteger('payload_version')->default(1);
            $table->jsonb('payload');
            $table->jsonb('context')->default('{}');
            $table->string('aggregate_type', 40);
            $table->uuid('aggregate_id');
            $table->string('status', 12)->default('pending');
            $table->integer('attempts')->default(0);
            $table->timestampTz('available_at')->useCurrent();
            $table->string('lease_owner', 80)->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['status', 'available_at']);
            $table->index(['tenant_id', 'aggregate_type', 'aggregate_id']);
        });
        DB::statement("alter table outbox_messages add constraint outbox_messages_status_check check (status in ('pending', 'published', 'dead'))");
        TenantSchema::enableRowLevelSecurity('outbox_messages');

        Schema::create('outbox_failures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'outbox_message_id', 'outbox_messages', cascade: true);
            $table->integer('attempt');
            $table->string('stage', 20);
            $table->text('error');
            $table->timestampTz('created_at')->useCurrent();
        });
        TenantSchema::enableRowLevelSecurity('outbox_failures');
        TenantSchema::appendOnly('outbox_failures');

        // Payloads da outbox não são visíveis a compradores; a restrição vale
        // só para o papel da aplicação, não para o relay.
        $appRole = TenantSchema::appRole();
        foreach (['outbox_messages', 'outbox_failures'] as $outboxTable) {
            DB::statement("create policy internal_only on {$outboxTable} as restrictive for all to {$appRole}
                using (app_sees_all_buyers()) with check (app_sees_all_buyers())");
        }

        $relay = TenantSchema::relayRole();
        DB::statement("grant select, update on outbox_messages to {$relay}");
        DB::statement("grant select, insert on outbox_failures to {$relay}");
        DB::statement("create policy relay_access on outbox_messages as permissive for all to {$relay} using (true) with check (true)");
        DB::statement("create policy relay_access on outbox_failures as permissive for all to {$relay} using (true) with check (true)");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_failures');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_item_costs');
        DB::statement('alter table if exists stock_reservations drop constraint if exists stock_reservations_order_item_fk');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        DB::statement('drop sequence if exists order_number_seq');
        Schema::dropIfExists('shopping_list_items');
        Schema::dropIfExists('shopping_lists');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
