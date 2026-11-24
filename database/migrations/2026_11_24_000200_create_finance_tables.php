<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contas a receber (RF-100, RF-101, RN-100, RN-101, RS-100, ADR-028):
 *
 * - faturas comerciais (não fiscais) sobre o expedido ainda não faturado,
 *   com itens, e parcelas pela condição de pagamento do pedido;
 * - pagamentos (manuais ou pelo gateway) por parcela, com taxa, e
 *   reembolsos cuja soma pendente e efetivada nunca passa do pago (CHECK);
 * - lançamentos financeiros somente inclusão: correções só por
 *   lançamento compensatório;
 * - eventos recebidos dos gateways, únicos por provedor e identificador;
 * - configuração do gateway por distribuidor, invisível ao comprador.
 *
 * Nenhum dado de cartão é armazenado: o pagamento com cartão acontece na
 * página do gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = TenantSchema::appRole();

        Schema::table('order_items', function (Blueprint $table): void {
            $table->decimal('quantity_invoiced', 18, 3)->default(0);
        });
        DB::statement('alter table order_items add constraint order_items_invoiced_check check (quantity_invoiced >= 0 and quantity_invoiced <= quantity_shipped)');
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('freight_invoiced')->default(false);
        });

        Schema::create('finance_number_counters', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->bigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement('alter table finance_number_counters add constraint finance_number_counters_check check (last_number >= 0)');
        DB::statement('alter table finance_number_counters enable row level security');
        DB::statement('alter table finance_number_counters force row level security');
        DB::statement('create policy tenant_isolation on finance_number_counters as permissive for all
            using (tenant_id = app_current_tenant()) with check (tenant_id = app_current_tenant())');
        TenantSchema::buyerReadOnly('finance_number_counters');

        Schema::create('invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'order_id', 'orders');
            $table->string('number', 20);
            $table->string('status', 10)->default('open');
            $table->bigInteger('items_cents');
            $table->bigInteger('freight_cents')->default(0);
            $table->bigInteger('total_cents');
            $table->bigInteger('paid_cents')->default(0);
            $table->bigInteger('credited_cents')->default(0);
            $table->bigInteger('adjusted_cents')->default(0);
            $table->jsonb('payment_term');
            $table->date('issue_date');
            $table->foreignUuid('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'issue_date']);
        });
        DB::statement("alter table invoices add constraint invoices_values_check check (
            status in ('open', 'paid') and items_cents >= 0 and freight_cents >= 0 and total_cents = items_cents + freight_cents and total_cents > 0
            and paid_cents >= 0 and credited_cents >= 0
            and paid_cents + credited_cents <= total_cents + adjusted_cents
            and (status = 'paid') = (paid_cents + credited_cents = total_cents + adjusted_cents))");
        TenantSchema::companyKey('invoices');
        TenantSchema::enableRowLevelSecurity('invoices', 'required');
        TenantSchema::buyerReadOnly('invoices');

        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'invoice_id', 'invoices');
            TenantSchema::companyReference($table, 'order_item_id', 'order_items');
            $table->decimal('quantity', 18, 3);
            $table->bigInteger('amount_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'invoice_id', 'order_item_id']);
        });
        DB::statement('alter table invoice_items add constraint invoice_items_values_check check (quantity > 0 and amount_cents >= 0)');
        TenantSchema::enableRowLevelSecurity('invoice_items', 'required');
        TenantSchema::buyerReadOnly('invoice_items');
        TenantSchema::appendOnly('invoice_items');

        Schema::create('receivable_installments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'invoice_id', 'invoices');
            $table->smallInteger('sequence');
            $table->date('due_date');
            $table->bigInteger('amount_cents');
            $table->bigInteger('adjusted_cents')->default(0);
            $table->bigInteger('paid_cents')->default(0);
            $table->bigInteger('credited_cents')->default(0);
            $table->string('status', 10)->default('open');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'invoice_id', 'sequence']);
            $table->index(['tenant_id', 'buyer_company_id', 'status', 'due_date']);
        });
        DB::statement("alter table receivable_installments add constraint receivable_installments_values_check check (
            status in ('open', 'paid') and amount_cents > 0 and amount_cents + adjusted_cents >= 0
            and paid_cents >= 0 and credited_cents >= 0
            and paid_cents + credited_cents <= amount_cents + adjusted_cents
            and (status = 'paid') = (paid_cents + credited_cents = amount_cents + adjusted_cents))");
        TenantSchema::companyKey('receivable_installments');
        TenantSchema::enableRowLevelSecurity('receivable_installments', 'required');
        TenantSchema::buyerReadOnly('receivable_installments');

        Schema::create('payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'installment_id', 'receivable_installments');
            $table->bigInteger('amount_cents');
            $table->bigInteger('fee_cents')->default(0);
            $table->string('method', 10);
            $table->string('source', 10);
            $table->string('gateway', 12)->nullable();
            $table->string('gateway_charge_id', 80)->nullable();
            $table->string('checkout_url', 500)->nullable();
            $table->string('status', 10)->default('pending');
            $table->bigInteger('refunded_cents')->default(0);
            $table->bigInteger('refund_pending_cents')->default(0);
            // Recebido que não quitou a parcela (excedente ou parcela já quitada): reembolsável.
            $table->bigInteger('unapplied_cents')->default(0);
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'gateway', 'gateway_charge_id']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        DB::statement("alter table payments add constraint payments_values_check check (
            amount_cents > 0 and fee_cents >= 0 and fee_cents <= amount_cents
            and method in ('pix', 'boleto', 'card', 'transfer', 'cash')
            and source in ('manual', 'gateway') and (source = 'gateway') = (gateway is not null)
            and (gateway is null or gateway in ('simulator', 'asaas'))
            and status in ('pending', 'confirmed', 'failed', 'cancelled')
            and (status = 'confirmed') = (confirmed_at is not null)
            and refunded_cents >= 0 and refund_pending_cents >= 0
            and refunded_cents + refund_pending_cents <= amount_cents
            and unapplied_cents >= 0 and unapplied_cents <= amount_cents
            and (status = 'confirmed' or refunded_cents + refund_pending_cents = 0))");
        TenantSchema::companyKey('payments');
        TenantSchema::enableRowLevelSecurity('payments', 'required');
        TenantSchema::buyerReadOnly('payments');

        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'payment_id', 'payments');
            $table->bigInteger('amount_cents');
            $table->string('status', 10)->default('pending');
            $table->string('reason', 500);
            $table->string('gateway_refund_id', 80)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        // verifying: o gateway não confirmou nem recusou (tempo esgotado); a reserva continua até a equipe conferir.
        DB::statement("alter table payment_refunds add constraint payment_refunds_values_check check (amount_cents > 0 and status in ('pending', 'verifying', 'done', 'failed'))");
        TenantSchema::enableRowLevelSecurity('payment_refunds', 'required');
        TenantSchema::buyerReadOnly('payment_refunds');

        Schema::create('finance_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'invoice_id', 'invoices');
            TenantSchema::companyReference($table, 'installment_id', 'receivable_installments', nullable: true);
            TenantSchema::companyReference($table, 'payment_id', 'payments', nullable: true);
            $table->string('type', 20);
            // Efeito no saldo a receber: positivo aumenta, negativo reduz.
            $table->bigInteger('receivable_delta');
            $table->bigInteger('amount_cents');
            $table->string('reason', 500)->nullable();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'invoice_id', 'created_at']);
        });
        DB::statement("alter table finance_entries add constraint finance_entries_type_check check (amount_cents >= 0 and case type
            when 'invoice_issued' then receivable_delta > 0
            when 'payment' then receivable_delta < 0
            when 'fee' then receivable_delta = 0
            when 'refund' then receivable_delta > 0
            when 'credit_note' then receivable_delta < 0
            when 'adjustment' then receivable_delta <> 0
            else false end)");
        TenantSchema::enableRowLevelSecurity('finance_entries', 'required');
        TenantSchema::buyerReadOnly('finance_entries');
        TenantSchema::appendOnly('finance_entries');

        Schema::create('gateway_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('gateway', 12);
            $table->string('event_id', 120);
            $table->string('event_type', 60);
            $table->string('charge_id', 80)->nullable();
            $table->jsonb('payload');
            $table->string('status', 10)->default('pending');
            $table->string('error', 500)->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();

            $table->unique(['tenant_id', 'gateway', 'event_id']);
            $table->index(['tenant_id', 'status', 'received_at']);
        });
        DB::statement("alter table gateway_webhook_events add constraint gateway_webhook_events_status_check check (status in ('pending', 'processed', 'ignored', 'failed'))");
        TenantSchema::enableRowLevelSecurity('gateway_webhook_events');
        DB::statement("create policy internal_only on gateway_webhook_events as restrictive for all to {$app}
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())");

        Schema::create('payment_gateway_settings', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->string('gateway', 12)->default('simulator');
            // Chave de API cifrada pela aplicação; hash do token do webhook.
            $table->text('api_key_encrypted')->nullable();
            $table->string('webhook_token_hash', 64)->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table payment_gateway_settings add constraint payment_gateway_settings_check check (gateway in ('simulator', 'asaas'))");
        DB::statement('alter table payment_gateway_settings enable row level security');
        DB::statement('alter table payment_gateway_settings force row level security');
        DB::statement('create policy tenant_isolation on payment_gateway_settings as permissive for all
            using (tenant_id = app_current_tenant()) with check (tenant_id = app_current_tenant())');
        DB::statement("create policy internal_only on payment_gateway_settings as restrictive for all to {$app}
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())");
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
        Schema::dropIfExists('gateway_webhook_events');
        Schema::dropIfExists('finance_entries');
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('receivable_installments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('finance_number_counters');
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('freight_invoiced');
        });
        DB::statement('alter table order_items drop constraint if exists order_items_invoiced_check');
        Schema::table('order_items', function (Blueprint $table): void {
            $table->dropColumn('quantity_invoiced');
        });
    }
};
