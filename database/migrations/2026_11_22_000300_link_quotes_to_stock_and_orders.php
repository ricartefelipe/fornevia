<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reserva de estoque e pedido vinculados à versão da cotação (RN-042,
 * RN-043, ADR-025):
 *
 * - stock_reservations ganha o titular item de cotação, com prazo
 *   obrigatório; cada reserva tem exatamente um titular;
 * - orders.source_quote_version_id passa a ter chave por empresa; pedido de
 *   cotação só nasce de versão aceita, com o total e a condição dela
 *   (trigger), e a origem não muda depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table): void {
            TenantSchema::reference($table, 'quote_item_id', 'quote_items', nullable: true);
        });
        DB::statement('alter table stock_reservations add constraint stock_reservations_holder_check check (num_nonnulls(order_item_id, quote_item_id) = 1)');
        DB::statement('alter table stock_reservations add constraint stock_reservations_quote_expiry_check check (quote_item_id is null or expires_at is not null)');
        DB::statement("create index stock_reservations_active_quote_index on stock_reservations (tenant_id, quote_item_id) where status = 'active' and quote_item_id is not null");

        DB::statement('alter table orders add constraint orders_source_quote_version_company_fk
            foreign key (tenant_id, source_quote_version_id, buyer_company_id) references quote_versions (tenant_id, id, buyer_company_id) on delete restrict');
        DB::statement("alter table orders add constraint orders_quote_source_check check ((source = 'quote') = (source_quote_version_id is not null))");

        DB::unprepared(<<<'SQL'
            create or replace function orders_quote_source_guard() returns trigger
                language plpgsql
                as $$
                declare
                    version record;
                begin
                    if tg_op = 'UPDATE' then
                        if new.source is distinct from old.source or new.source_quote_version_id is distinct from old.source_quote_version_id then
                            raise exception 'pedido %: a origem não muda', old.id
                                using errcode = 'check_violation';
                        end if;

                        return new;
                    end if;

                    if new.source = 'quote' then
                        select v.status, v.total_cents, v.payment_term_id into version
                          from quote_versions v
                         where v.id = new.source_quote_version_id and v.tenant_id = new.tenant_id;

                        if version.status is distinct from 'accepted'
                            or version.total_cents is distinct from new.total_cents
                            or version.payment_term_id is distinct from new.payment_term_id then
                            raise exception 'pedido de cotação exige versão aceita com o mesmo total e a mesma condição'
                                using errcode = 'check_violation';
                        end if;
                    end if;

                    return new;
                end
                $$;

            create trigger orders_quote_source_guard before insert or update of source, source_quote_version_id on orders
                for each row execute function orders_quote_source_guard();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists orders_quote_source_guard on orders;
            drop function if exists orders_quote_source_guard();
            SQL);
        DB::statement('alter table orders drop constraint if exists orders_quote_source_check');
        DB::statement('alter table orders drop constraint if exists orders_source_quote_version_company_fk');
        DB::statement('drop index if exists stock_reservations_active_quote_index');
        DB::statement('alter table stock_reservations drop constraint if exists stock_reservations_quote_expiry_check');
        DB::statement('alter table stock_reservations drop constraint if exists stock_reservations_holder_check');
        Schema::table('stock_reservations', function (Blueprint $table): void {
            $table->dropForeign(['tenant_id', 'quote_item_id']);
            $table->dropIndex(['tenant_id', 'quote_item_id']);
            $table->dropColumn('quote_item_id');
        });
    }
};
