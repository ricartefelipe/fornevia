<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cotações e negociação (RF-040, RN-040 a RN-044, ADR-025).
 *
 * - quotes: cotação da empresa compradora, numerada por tenant;
 * - quote_requests / quote_request_items: solicitação e pedidos de revisão
 *   do comprador (append-only, sem preço);
 * - quote_versions / quote_items: propostas da equipe, imutáveis desde a
 *   inserção (privilégio de UPDATE só nas colunas de situação; itens
 *   append-only);
 * - quote_commercial_reviews: avaliação comercial interna 1:1 com a versão
 *   (ADR-020: o comprador não lê);
 * - quote_comments: conversa compartilhada e notas internas.
 *
 * Todas são da empresa compradora (RLS required), com chaves compostas por
 * empresa, e somente leitura no contexto de comprador (ADR-024): as Actions
 * do portal gravam no contexto de sistema do mesmo usuário. As transições e
 * a conferência dos totais ficam na migration seguinte (triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = TenantSchema::appRole();

        Schema::create('quote_number_counters', function (Blueprint $table): void {
            $table->uuid('tenant_id')->primary();
            $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->bigInteger('last_number')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement('alter table quote_number_counters add constraint quote_number_counters_positive_check check (last_number >= 0)');
        DB::statement('alter table quote_number_counters enable row level security');
        DB::statement('alter table quote_number_counters force row level security');
        DB::statement('create policy tenant_isolation on quote_number_counters as permissive for all
            using (tenant_id = app_current_tenant()) with check (tenant_id = app_current_tenant())');
        TenantSchema::buyerReadOnly('quote_number_counters');

        Schema::create('quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('number', 20);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            $table->foreignUuid('requested_by_user_id')->constrained('users')->restrictOnDelete();
            TenantSchema::companyReference($table, 'requested_by_membership_id', 'memberships');
            TenantSchema::reference($table, 'sales_rep_membership_id', 'memberships', nullable: true);
            $table->string('status', 12)->default('requested');
            $table->date('needed_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('close_reason_code', 20)->nullable();
            $table->text('close_reason')->nullable();
            $table->timestampTz('last_activity_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'buyer_company_id', 'status', 'last_activity_at'], 'quotes_company_status_index');
            $table->index(['tenant_id', 'status', 'last_activity_at'], 'quotes_status_index');
        });
        DB::statement("alter table quotes add constraint quotes_values_check check (
            status in ('requested', 'proposed', 'expired', 'accepted', 'rejected', 'cancelled')
            and (closed_at is not null) = (status in ('accepted', 'rejected', 'cancelled'))
            and (status not in ('rejected', 'cancelled') or (close_reason_code is not null))
            and (close_reason_code is null or close_reason_code in ('price', 'lead_time', 'payment_term', 'other_supplier', 'not_needed', 'other', 'staff_closed'))
            and (close_reason_code is distinct from 'other' and close_reason_code is distinct from 'staff_closed' or char_length(trim(coalesce(close_reason, ''))) >= 5)
            and (close_reason is null or char_length(close_reason) <= 500))");
        TenantSchema::companyKey('quotes');
        TenantSchema::enableRowLevelSecurity('quotes', 'required');
        TenantSchema::buyerReadOnly('quotes');
        DB::statement("revoke update, delete, truncate on quotes from {$app}");
        DB::statement("grant update (number, status, closed_at, closed_by_user_id, close_reason_code, close_reason, last_activity_at, updated_at) on quotes to {$app}");

        Schema::create('quote_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'quote_id', 'quotes');
            $table->smallInteger('version_number')->nullable();
            $table->string('status', 20);
            $table->foreignUuid('created_by_user_id')->constrained('users')->restrictOnDelete();
            TenantSchema::reference($table, 'created_by_membership_id', 'memberships');
            TenantSchema::reference($table, 'payment_term_id', 'payment_terms');
            $table->jsonb('payment_term');
            $table->timestampTz('valid_until');
            $table->timestampTz('stock_hold_until')->nullable();
            $table->smallInteger('item_count');
            $table->bigInteger('subtotal_cents');
            $table->bigInteger('discount_cents');
            $table->bigInteger('total_cents');
            $table->bigInteger('price_version');
            $table->text('notes')->nullable();
            $table->char('content_fingerprint', 64);
            $table->timestampTz('sent_at')->nullable();
            TenantSchema::reference($table, 'sent_by_membership_id', 'memberships', nullable: true);
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            TenantSchema::companyReference($table, 'accepted_by_membership_id', 'memberships', nullable: true);
            $table->timestampTz('closed_at')->nullable();
            $table->string('stock_hold_status', 8)->default('none');
            $table->timestampTz('stock_hold_ended_at')->nullable();
            $table->string('stock_hold_end_reason', 16)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'quote_id', 'version_number'], 'quote_versions_number_unique');
            $table->unique(['tenant_id', 'id', 'quote_id'], 'quote_versions_quote_unique');
        });
        DB::statement("alter table quote_versions add constraint quote_versions_values_check check (
            status in ('pending_approval', 'ready', 'sent', 'accepted', 'superseded', 'countered', 'rejected', 'expired', 'withdrawn', 'approval_rejected')
            and item_count between 1 and 100
            and subtotal_cents > 0 and discount_cents >= 0 and total_cents > 0
            and total_cents = subtotal_cents - discount_cents
            and jsonb_typeof(payment_term) = 'object'
            and (notes is null or char_length(notes) <= 2000)
            and (stock_hold_until is null or stock_hold_until <= valid_until)
            and (version_number is null) = (sent_at is null)
            and (status not in ('pending_approval', 'ready', 'approval_rejected') or sent_at is null)
            and (status not in ('sent', 'accepted', 'countered', 'rejected', 'superseded') or sent_at is not null)
            and (accepted_at is not null) = (status = 'accepted')
            and (accepted_at is null) = (accepted_by_membership_id is null)
            and (accepted_at is null) = (accepted_by_user_id is null)
            and (closed_at is null) = (status in ('pending_approval', 'ready', 'sent'))
            and stock_hold_status in ('none', 'active', 'ended')
            and (stock_hold_status = 'none' or (stock_hold_until is not null and sent_at is not null))
            and (stock_hold_status <> 'active' or status in ('sent', 'accepted'))
            and (stock_hold_status = 'ended') = (stock_hold_ended_at is not null)
            and (stock_hold_ended_at is null) = (stock_hold_end_reason is null)
            and (stock_hold_end_reason is null or stock_hold_end_reason in ('expired', 'superseded', 'countered', 'rejected', 'withdrawn', 'cancelled', 'transferred', 'order_rejected', 'order_cancelled')))");
        DB::statement("create unique index quote_versions_one_in_preparation on quote_versions (tenant_id, quote_id) where status in ('pending_approval', 'ready')");
        DB::statement("create unique index quote_versions_one_with_buyer on quote_versions (tenant_id, quote_id) where status = 'sent'");
        DB::statement("create unique index quote_versions_one_accepted on quote_versions (tenant_id, quote_id) where status = 'accepted'");
        DB::statement("create index quote_versions_validity_index on quote_versions (tenant_id, valid_until) where status in ('pending_approval', 'ready', 'sent')");
        DB::statement("create index quote_versions_hold_index on quote_versions (tenant_id, stock_hold_until) where stock_hold_status = 'active'");
        TenantSchema::companyKey('quote_versions');
        TenantSchema::enableRowLevelSecurity('quote_versions', 'required');
        TenantSchema::buyerReadOnly('quote_versions');
        // O comprador só vê versões enviadas.
        DB::statement('create policy buyer_sees_sent on quote_versions as restrictive for select using (app_sees_all_buyers() or sent_at is not null)');
        DB::statement("revoke update, delete, truncate on quote_versions from {$app}");
        DB::statement("grant update (status, version_number, sent_at, sent_by_membership_id, accepted_at, accepted_by_user_id, accepted_by_membership_id,
            closed_at, stock_hold_status, stock_hold_ended_at, stock_hold_end_reason, updated_at) on quote_versions to {$app}");

        Schema::create('quote_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'quote_version_id', 'quote_versions');
            $table->smallInteger('line_number');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->string('sku_code', 60);
            $table->string('product_name', 200);
            $table->string('variant_label', 120)->nullable();
            $table->string('unit_code', 10);
            $table->string('package_label', 120)->nullable();
            $table->decimal('conversion_factor', 18, 6);
            $table->string('packaging_fingerprint', 100);
            $table->decimal('quantity', 18, 3);
            $table->decimal('base_quantity', 18, 3);
            $table->bigInteger('unit_price_cents');
            $table->integer('discount_bps');
            $table->bigInteger('unit_net_price_cents');
            $table->bigInteger('line_gross_cents');
            $table->bigInteger('line_total_cents');
            $table->jsonb('price_source');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'quote_version_id', 'line_number'], 'quote_items_line_unique');
            $table->unique(['tenant_id', 'quote_version_id', 'sku_id'], 'quote_items_sku_unique');
        });
        // Mesmo cálculo de Money::discountedBy, Money::times e LineAmounts
        // (HALF_UP para valores positivos).
        DB::statement('alter table quote_items add constraint quote_items_values_check check (
            line_number between 1 and 100 and quantity > 0 and base_quantity > 0 and conversion_factor > 0
            and unit_price_cents > 0 and unit_net_price_cents > 0 and discount_bps between 0 and 9999
            and unit_net_price_cents = round(unit_price_cents::numeric * (10000 - discount_bps) / 10000)
            and line_gross_cents = round(unit_price_cents * quantity)
            and line_total_cents = round(unit_net_price_cents * quantity)
            and line_gross_cents >= line_total_cents
            and jsonb_typeof(price_source) = \'object\')');
        TenantSchema::enableRowLevelSecurity('quote_items', 'required');
        TenantSchema::buyerReadOnly('quote_items');
        TenantSchema::appendOnly('quote_items');
        // O item herda a visibilidade da versão (comprador: só enviadas).
        DB::statement('create policy version_visible on quote_items as restrictive for select
            using (app_sees_all_buyers() or exists (select 1 from quote_versions v where v.id = quote_items.quote_version_id))');

        Schema::create('quote_commercial_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'quote_version_id', 'quote_versions');
            $table->foreignUuid('evaluated_by_user_id')->constrained('users')->restrictOnDelete();
            TenantSchema::reference($table, 'evaluated_by_membership_id', 'memberships');
            $table->integer('author_limit_bps');
            $table->integer('max_discount_bps');
            $table->boolean('requires_approval');
            $table->jsonb('violations');
            $table->jsonb('lines');
            $table->text('justification')->nullable();
            $table->string('decision', 10)->nullable();
            $table->foreignUuid('decided_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            TenantSchema::reference($table, 'decided_by_membership_id', 'memberships', nullable: true);
            $table->integer('decider_limit_bps')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->boolean('self_approved')->default(false);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'quote_version_id'], 'quote_commercial_reviews_version_unique');
        });
        DB::statement("alter table quote_commercial_reviews add constraint quote_commercial_reviews_values_check check (
            jsonb_typeof(violations) = 'array' and jsonb_typeof(lines) = 'array'
            and author_limit_bps between 0 and 10000 and max_discount_bps between 0 and 9999
            and requires_approval = (jsonb_array_length(violations) > 0)
            and (requires_approval or decision is null)
            and (requires_approval = false or char_length(trim(coalesce(justification, ''))) >= 10)
            and (justification is null or char_length(justification) <= 1000)
            and (decision is null or decision in ('approved', 'rejected'))
            and num_nonnulls(decision, decided_by_user_id, decided_by_membership_id, decider_limit_bps, decided_at) in (0, 5)
            and (decision is distinct from 'approved' or decider_limit_bps >= max_discount_bps)
            and (decision is distinct from 'rejected' or char_length(trim(coalesce(decision_comment, ''))) >= 5)
            and (decision_comment is null or char_length(decision_comment) <= 1000)
            and self_approved = (decision is not null and decided_by_membership_id is not distinct from evaluated_by_membership_id)
            and (not self_approved or decision = 'approved'))");
        TenantSchema::enableRowLevelSecurity('quote_commercial_reviews', 'required');
        // Custo, margem e mínimos são internos (ADR-020).
        DB::statement('create policy internal_only on quote_commercial_reviews as restrictive for all
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())');
        DB::statement("revoke update, delete, truncate on quote_commercial_reviews from {$app}");
        DB::statement("grant update (decision, decided_by_user_id, decided_by_membership_id, decider_limit_bps, decision_comment, decided_at, self_approved, updated_at)
            on quote_commercial_reviews to {$app}");

        Schema::create('quote_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'quote_id', 'quotes');
            $table->smallInteger('sequence');
            $table->string('kind', 12);
            $table->uuid('responds_to_version_id')->nullable();
            $table->text('message')->nullable();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            TenantSchema::companyReference($table, 'author_membership_id', 'memberships');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'quote_id', 'sequence'], 'quote_requests_sequence_unique');
        });
        DB::statement('alter table quote_requests add constraint quote_requests_version_fk
            foreign key (tenant_id, responds_to_version_id, quote_id) references quote_versions (tenant_id, id, quote_id) on delete restrict');
        DB::statement("alter table quote_requests add constraint quote_requests_values_check check (
            kind in ('initial', 'counteroffer') and sequence between 1 and 100
            and (kind = 'initial') = (responds_to_version_id is null)
            and (kind = 'initial' or char_length(trim(coalesce(message, ''))) >= 5)
            and (message is null or char_length(message) <= 2000))");
        DB::statement("create unique index quote_requests_one_initial on quote_requests (tenant_id, quote_id) where kind = 'initial'");
        TenantSchema::companyKey('quote_requests');
        TenantSchema::enableRowLevelSecurity('quote_requests', 'required');
        TenantSchema::buyerReadOnly('quote_requests');
        TenantSchema::appendOnly('quote_requests');

        Schema::create('quote_request_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'quote_request_id', 'quote_requests');
            $table->smallInteger('line_number');
            TenantSchema::reference($table, 'sku_id', 'skus');
            $table->string('sku_code', 60);
            $table->string('product_name', 200);
            $table->string('variant_label', 120)->nullable();
            $table->string('unit_code', 10);
            $table->decimal('quantity', 18, 3);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'quote_request_id', 'line_number'], 'quote_request_items_line_unique');
            $table->unique(['tenant_id', 'quote_request_id', 'sku_id'], 'quote_request_items_sku_unique');
        });
        DB::statement('alter table quote_request_items add constraint quote_request_items_values_check check (quantity > 0 and line_number between 1 and 100)');
        TenantSchema::enableRowLevelSecurity('quote_request_items', 'required');
        TenantSchema::buyerReadOnly('quote_request_items');
        TenantSchema::appendOnly('quote_request_items');

        Schema::create('quote_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'buyer_company_id', 'buyer_companies');
            TenantSchema::companyReference($table, 'quote_id', 'quotes');
            $table->uuid('quote_version_id')->nullable();
            $table->foreignUuid('author_user_id')->constrained('users')->restrictOnDelete();
            TenantSchema::companyReference($table, 'buyer_membership_id', 'memberships', nullable: true);
            TenantSchema::reference($table, 'staff_membership_id', 'memberships', nullable: true);
            $table->string('author_name', 120);
            $table->string('visibility', 10);
            $table->text('body');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'quote_id', 'created_at'], 'quote_comments_quote_index');
        });
        DB::statement('alter table quote_comments add constraint quote_comments_version_fk
            foreign key (tenant_id, quote_version_id, quote_id) references quote_versions (tenant_id, id, quote_id) on delete restrict');
        DB::statement("alter table quote_comments add constraint quote_comments_values_check check (
            visibility in ('shared', 'internal') and char_length(trim(body)) between 1 and 2000
            and num_nonnulls(buyer_membership_id, staff_membership_id) = 1
            and (buyer_membership_id is null or visibility = 'shared'))");
        TenantSchema::enableRowLevelSecurity('quote_comments', 'required');
        TenantSchema::buyerReadOnly('quote_comments');
        TenantSchema::appendOnly('quote_comments');
        // Notas internas não chegam ao comprador.
        DB::statement("create policy shared_only on quote_comments as restrictive for select using (app_sees_all_buyers() or visibility = 'shared')");
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_comments');
        Schema::dropIfExists('quote_request_items');
        Schema::dropIfExists('quote_requests');
        Schema::dropIfExists('quote_commercial_reviews');
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quote_versions');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('quote_number_counters');
    }
};
