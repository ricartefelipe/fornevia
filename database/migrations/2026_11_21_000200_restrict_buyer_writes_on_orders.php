<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pedidos, itens e eventos são somente leitura no contexto de comprador
 * (ADR-020): situação comercial, aprovação, solicitante, valores e
 * histórico decidem a aprovação interna e a confirmação, e não podem ser
 * alterados por uma conexão de comprador, mesmo da própria empresa. As
 * Actions gravam no contexto de sistema do mesmo usuário (ADR-014), depois
 * de autorizar no contexto de comprador.
 *
 * A situação de aprovação ganha "cancelled": pedido cancelado enquanto
 * aguardava aprovação. Centro de custo e unidade do pedido passam a ter
 * chave composta com a empresa (orçamento e regra de aprovação dependem
 * deles); as chaves alvo são criadas na migration da aprovação.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = ['orders', 'order_items', 'order_events'];

    /** @var array<string, string> coluna => tabela referenciada */
    private const array COMPANY_REFERENCES = ['cost_center_id' => 'cost_centers', 'buyer_unit_id' => 'buyer_units'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            TenantSchema::buyerReadOnly($table);
        }

        DB::statement('alter table orders drop constraint orders_approval_status_check');
        DB::statement("alter table orders add constraint orders_approval_status_check check (approval_status in ('not_required', 'pending', 'approved', 'rejected', 'cancelled'))");

        foreach (self::COMPANY_REFERENCES as $column => $referenced) {
            DB::statement("alter table orders drop constraint orders_tenant_id_{$column}_foreign");
            DB::statement("alter table orders add constraint orders_{$column}_company_fk
                foreign key (tenant_id, {$column}, buyer_company_id) references {$referenced} (tenant_id, id, buyer_company_id) on delete restrict");
        }
    }

    public function down(): void
    {
        foreach (self::COMPANY_REFERENCES as $column => $referenced) {
            DB::statement("alter table orders drop constraint orders_{$column}_company_fk");
            DB::statement("alter table orders add constraint orders_tenant_id_{$column}_foreign
                foreign key (tenant_id, {$column}) references {$referenced} (tenant_id, id) on delete restrict");
        }

        DB::statement('alter table orders drop constraint orders_approval_status_check');
        DB::statement("alter table orders add constraint orders_approval_status_check check (approval_status in ('not_required', 'pending', 'approved', 'rejected'))");

        foreach (self::TABLES as $table) {
            DB::statement("drop policy if exists staff_insert on {$table}");
            DB::statement("drop policy if exists staff_update on {$table}");
            DB::statement("drop policy if exists staff_delete on {$table}");
        }
    }
};
