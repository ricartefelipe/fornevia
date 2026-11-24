<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expedição, entrega, devoluções e frete (RF-090 a RF-092, RN-090, RN-091,
 * ADR-027):
 *
 * - order_items ganha o que está em separação e o que está em devolução
 *   aberta, com CHECKs: cancelado + expedido + em separação <= pedido;
 *   devolvido + em devolução <= entregue;
 * - remessas por pedido e depósito, itens e eventos (únicos por remessa e
 *   identificador externo, somente inclusão);
 * - devoluções com itens recebidos, aprovados e reprovados na inspeção;
 * - regras de frete do distribuidor e condição negociada por empresa;
 * - tudo somente leitura no contexto de comprador (ADR-024).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('quantity_picking', 18, 3)->default(0);
            $table->decimal('quantity_return_open', 18, 3)->default(0);
        });
        DB::statement('alter table order_items add constraint order_items_fulfillment_check check (
            quantity_picking >= 0 and quantity_return_open >= 0
            and quantity_cancelled + quantity_shipped + quantity_picking <= quantity
            and quantity_returned + quantity_return_open <= quantity_delivered)');
        TenantSchema::companyKey('order_items');

        Schema::create('fulfillment_number_counters', function (Blueprint $table): void {
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->string('kind', 10);
            $table->bigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['tenant_id', 'kind']);
        });
        DB::statement("alter table fulfillment_number_counters add constraint fulfillment_number_counters_check check (kind in ('shipment', 'return') and last_number >= 0)");
        DB::statement('alter table fulfillment_number_counters enable row level security');
        DB::statement('alter table fulfillment_number_counters force row level security');
        DB::statement('create policy tenant_isolation on fulfillment_number_counters as permissive for all
            using (tenant_id = app_current_tenant()) with check (tenant_id = app_current_tenant())');
        TenantSchema::buyerReadOnly('fulfillment_number_counters');

        Schema::create('shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'order_id', 'orders');
            TenantSchema::reference($table, 'warehouse_id', 'warehouses');
            $table->string('number', 20);
            $table->string('status', 12)->default('preparing');
            $table->string('carrier', 80)->nullable();
            $table->string('tracking_code', 80)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('checked_at')->nullable();
            $table->foreignUuid('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('shipped_at')->nullable();
            $table->foreignUuid('shipped_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        DB::statement("alter table shipments add constraint shipments_status_check check (status in ('preparing', 'shipped', 'delivered', 'cancelled'))");
        DB::statement("alter table shipments add constraint shipments_dates_check check (
            (status in ('shipped', 'delivered')) = (shipped_at is not null)
            and (status = 'delivered') = (delivered_at is not null)
            and (status = 'cancelled') = (cancelled_at is not null))");
        TenantSchema::companyKey('shipments');
        TenantSchema::enableRowLevelSecurity('shipments', 'required');
        TenantSchema::buyerReadOnly('shipments');

        Schema::create('shipment_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'shipment_id', 'shipments');
            TenantSchema::companyReference($table, 'order_item_id', 'order_items');
            $table->decimal('quantity', 18, 3);
            $table->decimal('quantity_checked', 18, 3)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'shipment_id', 'order_item_id']);
        });
        DB::statement('alter table shipment_items add constraint shipment_items_quantity_check check (
            quantity > 0 and (quantity_checked is null or (quantity_checked >= 0 and quantity_checked <= quantity)))');
        TenantSchema::enableRowLevelSecurity('shipment_items', 'required');
        TenantSchema::buyerReadOnly('shipment_items');

        Schema::create('shipment_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'shipment_id', 'shipments');
            $table->string('source', 10);
            $table->string('external_id', 120);
            $table->string('kind', 20);
            $table->string('description', 500)->nullable();
            $table->timestampTz('occurred_at');
            // Se o evento mudou a situação da remessa (falso: repetido ou antigo).
            $table->boolean('applied');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'shipment_id', 'external_id']);
            $table->index(['tenant_id', 'shipment_id', 'occurred_at']);
        });
        DB::statement("alter table shipment_events add constraint shipment_events_values_check check (
            source in ('staff', 'carrier') and kind in ('in_transit', 'out_for_delivery', 'delivered', 'exception'))");
        TenantSchema::enableRowLevelSecurity('shipment_events', 'required');
        TenantSchema::buyerReadOnly('shipment_events');
        TenantSchema::appendOnly('shipment_events');

        Schema::create('return_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'order_id', 'orders');
            $table->string('number', 20);
            $table->string('status', 12)->default('requested');
            $table->string('reason_code', 20);
            $table->string('reason', 1000);
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            TenantSchema::companyReference($table, 'requested_by_membership_id', 'memberships');
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_comment', 1000)->nullable();
            TenantSchema::reference($table, 'warehouse_id', 'warehouses', nullable: true);
            $table->boolean('inspection_required')->default(true);
            $table->timestampTz('received_at')->nullable();
            $table->foreignUuid('received_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->bigInteger('refund_cents')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        DB::statement("alter table return_requests add constraint return_requests_values_check check (
            status in ('requested', 'authorized', 'rejected', 'received', 'closed', 'cancelled')
            and reason_code in ('damaged', 'wrong_item', 'not_ordered', 'regret', 'other')
            and refund_cents >= 0
            and (status in ('authorized', 'received', 'closed')) <= (warehouse_id is not null)
            and (status in ('received', 'closed')) = (received_at is not null))");
        TenantSchema::companyKey('return_requests');
        TenantSchema::enableRowLevelSecurity('return_requests', 'required');
        TenantSchema::buyerReadOnly('return_requests');

        Schema::create('return_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'return_request_id', 'return_requests');
            TenantSchema::companyReference($table, 'order_item_id', 'order_items');
            $table->decimal('quantity', 18, 3);
            $table->decimal('quantity_received', 18, 3)->default(0);
            $table->decimal('quantity_approved', 18, 3)->default(0);
            $table->decimal('quantity_rejected', 18, 3)->default(0);
            $table->string('inspection_note', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'return_request_id', 'order_item_id']);
        });
        DB::statement('alter table return_items add constraint return_items_quantities_check check (
            quantity > 0 and quantity_received >= 0 and quantity_approved >= 0 and quantity_rejected >= 0
            and quantity_received <= quantity and quantity_approved + quantity_rejected <= quantity_received)');
        TenantSchema::enableRowLevelSecurity('return_items', 'required');
        TenantSchema::buyerReadOnly('return_items');

        Schema::create('freight_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('name', 80);
            // UFs atendidas pela regra; vazia = todas.
            $table->jsonb('states')->default('[]');
            $table->bigInteger('amount_cents');
            $table->bigInteger('free_from_cents')->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("alter table freight_rules add constraint freight_rules_values_check check (
            amount_cents >= 0 and (free_from_cents is null or free_from_cents > 0) and jsonb_typeof(states) = 'array')");
        TenantSchema::enableRowLevelSecurity('freight_rules');
        TenantSchema::buyerReadOnly('freight_rules');

        Schema::create('buyer_freight_conditions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            $table->string('kind', 10);
            $table->bigInteger('amount_cents')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'buyer_company_id']);
        });
        DB::statement("alter table buyer_freight_conditions add constraint buyer_freight_conditions_values_check check (
            kind in ('free', 'fixed') and amount_cents >= 0 and (kind = 'fixed' or amount_cents = 0))");
        TenantSchema::enableRowLevelSecurity('buyer_freight_conditions', 'required');
        TenantSchema::buyerReadOnly('buyer_freight_conditions');

        self::guards();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists shipments_guard on shipments;
            drop function if exists shipment_guard();
            drop trigger if exists shipment_items_guard on shipment_items;
            drop function if exists shipment_item_guard();
            SQL);
        Schema::dropIfExists('buyer_freight_conditions');
        Schema::dropIfExists('freight_rules');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('fulfillment_number_counters');
        DB::statement('alter table order_items drop constraint if exists order_items_buyer_unique');
        DB::statement('alter table order_items drop constraint if exists order_items_fulfillment_check');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn(['quantity_picking', 'quantity_return_open']);
        });
    }

    /**
     * Situação da remessa só avança (preparing → shipped → delivered, ou
     * preparing → cancelled); número, pedido, depósito e datas gravadas não
     * mudam; itens só mudam em separação.
     */
    private static function guards(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function shipment_guard() returns trigger
                language plpgsql
                as $$
                begin
                    if new.number is distinct from old.number or new.order_id is distinct from old.order_id
                        or new.warehouse_id is distinct from old.warehouse_id or new.buyer_company_id is distinct from old.buyer_company_id then
                        raise exception 'remessa %: número, pedido e depósito não mudam', old.id
                            using errcode = 'check_violation';
                    end if;

                    if new.status is distinct from old.status and not (
                        (old.status = 'preparing' and new.status in ('shipped', 'cancelled'))
                        or (old.status = 'shipped' and new.status = 'delivered')) then
                        raise exception 'remessa %: transição % para % não permitida', old.id, old.status, new.status
                            using errcode = 'check_violation';
                    end if;

                    if old.status <> 'preparing' and (new.carrier is distinct from old.carrier
                        or new.tracking_code is distinct from old.tracking_code or new.shipped_at is distinct from old.shipped_at) then
                        raise exception 'remessa % expedida: transportadora, rastreamento e expedição não mudam', old.id
                            using errcode = 'check_violation';
                    end if;

                    return new;
                end
                $$;

            create trigger shipments_guard before update on shipments
                for each row execute function shipment_guard();

            create or replace function shipment_item_guard() returns trigger
                language plpgsql
                as $$
                declare
                    shipment_status text;
                begin
                    select s.status into shipment_status from shipments s
                     where s.id = coalesce(new.shipment_id, old.shipment_id) and s.tenant_id = coalesce(new.tenant_id, old.tenant_id);

                    if shipment_status is distinct from 'preparing' then
                        raise exception 'itens de remessa só mudam em separação'
                            using errcode = 'check_violation';
                    end if;

                    return coalesce(new, old);
                end
                $$;

            create trigger shipment_items_guard before insert or update or delete on shipment_items
                for each row execute function shipment_item_guard();
            SQL);
    }
};
