<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estoque por depósito como ledger (ADR-005).
 *
 * - stock_movements: append-only, com deltas explícitos de físico e reservado;
 * - stock_levels: projeção mantida por trigger no INSERT do movimento; a
 *   aplicação não grava saldos (só lê e bloqueia);
 * - CHECKs garantem físico >= 0, reservado >= 0 e reservado <= físico;
 * - stock_reservations: reservas por item de pedido, com consumo e liberação.
 *
 * Contextos de comprador apenas leem: reservas da confirmação de pedido são
 * gravadas no contexto de sistema do mesmo usuário (ADR-014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'branch_id', 'branches');
            $table->string('code', 20);
            $table->string('name', 120);
            $table->integer('priority')->default(0);
            $table->boolean('allows_sales')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });
        TenantSchema::enableRowLevelSecurity('warehouses');
        TenantSchema::buyerReadOnly('warehouses');

        Schema::create('stock_levels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'warehouse_id', 'warehouses');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->decimal('on_hand', 18, 3)->default(0);
            $table->decimal('reserved', 18, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(['tenant_id', 'warehouse_id', 'sku_id']);
        });
        DB::statement('alter table stock_levels add constraint stock_levels_on_hand_non_negative check (on_hand >= 0)');
        DB::statement('alter table stock_levels add constraint stock_levels_reserved_non_negative check (reserved >= 0)');
        DB::statement('alter table stock_levels add constraint stock_levels_reserved_lte_on_hand check (reserved <= on_hand)');
        TenantSchema::enableRowLevelSecurity('stock_levels');
        TenantSchema::buyerReadOnly('stock_levels');
        TenantSchema::projection('stock_levels');

        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'warehouse_id', 'warehouses');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->uuid('order_item_id')->nullable();
            $table->decimal('quantity', 18, 3);
            $table->decimal('quantity_consumed', 18, 3)->default(0);
            $table->decimal('quantity_released', 18, 3)->default(0);
            $table->string('status', 10)->default('active');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_item_id']);
        });
        DB::statement("alter table stock_reservations add constraint stock_reservations_status_check check (status in ('active', 'consumed', 'released'))");
        DB::statement('alter table stock_reservations add constraint stock_reservations_quantities_check check (
            quantity > 0 and quantity_consumed >= 0 and quantity_released >= 0 and quantity_consumed + quantity_released <= quantity)');
        DB::statement("alter table stock_reservations add constraint stock_reservations_closed_check check (
            (status = 'active') = (quantity_consumed + quantity_released < quantity))");
        TenantSchema::enableRowLevelSecurity('stock_reservations');
        TenantSchema::buyerReadOnly('stock_reservations');

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'warehouse_id', 'warehouses');
            TenantSchema::reference($table, 'sku_id', 'skus');
            TenantSchema::reference($table, 'reservation_id', 'stock_reservations', nullable: true);
            $table->string('type', 24);
            $table->decimal('quantity', 18, 3);
            $table->decimal('on_hand_delta', 18, 3);
            $table->decimal('reserved_delta', 18, 3);
            $table->string('reference_type', 40)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->uuid('transfer_id')->nullable();
            $table->text('reason')->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('correlation_id', 128)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'warehouse_id', 'sku_id', 'created_at']);
            $table->index(['tenant_id', 'reference_type', 'reference_id']);
        });
        // Cada tipo tem sinais de delta fixos: o ledger é autoexplicativo e
        // um movimento incoerente é recusado pelo banco.
        DB::statement("alter table stock_movements add constraint stock_movements_type_check check (
            quantity > 0 and case type
                when 'receipt' then on_hand_delta = quantity and reserved_delta = 0
                when 'adjustment_in' then on_hand_delta = quantity and reserved_delta = 0
                when 'adjustment_out' then on_hand_delta = -quantity and reserved_delta = 0
                when 'transfer_in' then on_hand_delta = quantity and reserved_delta = 0
                when 'transfer_out' then on_hand_delta = -quantity and reserved_delta = 0
                when 'return_receipt' then on_hand_delta = quantity and reserved_delta = 0
                when 'reservation' then on_hand_delta = 0 and reserved_delta = quantity
                when 'reservation_release' then on_hand_delta = 0 and reserved_delta = -quantity
                when 'shipment' then on_hand_delta = -quantity and reserved_delta = -quantity
                else false
            end)");
        DB::statement("alter table stock_movements add constraint stock_movements_reservation_check check (
            (type in ('reservation', 'reservation_release', 'shipment')) = (reservation_id is not null))");
        DB::statement("alter table stock_movements add constraint stock_movements_transfer_check check (
            (type in ('transfer_in', 'transfer_out')) = (transfer_id is not null))");
        TenantSchema::enableRowLevelSecurity('stock_movements');
        TenantSchema::buyerReadOnly('stock_movements');
        TenantSchema::appendOnly('stock_movements');

        // Aplicador de deltas (sem regra de negócio): roda como dono da tabela,
        // ainda sujeito à RLS forçada e ao contexto da transação.
        DB::unprepared(<<<'SQL'
            create or replace function stock_apply_movement() returns trigger
                language plpgsql security definer set search_path = public
                as $$
                begin
                    insert into stock_levels (id, tenant_id, warehouse_id, sku_id, on_hand, reserved, updated_at)
                    values (uuidv7(), new.tenant_id, new.warehouse_id, new.sku_id, new.on_hand_delta, new.reserved_delta, now())
                    on conflict (tenant_id, warehouse_id, sku_id) do update
                        set on_hand = stock_levels.on_hand + excluded.on_hand,
                            reserved = stock_levels.reserved + excluded.reserved,
                            updated_at = now();
                    return null;
                end
                $$;

            revoke all on function stock_apply_movement() from public;

            create trigger stock_movements_apply after insert on stock_movements
                for each row execute function stock_apply_movement();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists stock_movements_apply on stock_movements; drop function if exists stock_apply_movement();');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('warehouses');
    }
};
