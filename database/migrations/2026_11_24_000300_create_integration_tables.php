<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integrações (RF-110 a RF-113, RS-110, ADR-029):
 *
 * - clientes de API do distribuidor, com as permissões concedidas, e tokens
 *   do Sanctum (hash SHA-256, abilities, prazo) vinculados ao tenant; a
 *   tabela de tokens é global para a busca do token antes do contexto, e
 *   o cliente é carregado depois, já no contexto do tenant;
 * - endpoints de webhook com segredo cifrado e eventos assinados, e as
 *   entregas, com identificador estável por evento e endpoint;
 * - importações CSV com linhas validadas, aplicadas uma única vez.
 *
 * Tudo interno ao distribuidor: invisível ao contexto de comprador.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = TenantSchema::appRole();
        $internal = static function (string $table) use ($app): void {
            TenantSchema::enableRowLevelSecurity($table);
            DB::statement("create policy internal_only on {$table} as restrictive for all to {$app}
                using (app_sees_all_buyers()) with check (app_sees_all_buyers())");
        };

        Schema::create('api_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('name', 80);
            $table->jsonb('permissions');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("alter table api_clients add constraint api_clients_permissions_check check (jsonb_typeof(permissions) = 'array')");
        $internal('api_clients');

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at')->nullable()->index();
            $table->timestampsTz();
        });
        // RLS forçado como nas demais tabelas: a aplicação só vê os tokens do
        // próprio tenant, fora do contexto de comprador. A autenticação, que
        // acontece antes de haver contexto, usa a função abaixo (security
        // definer, do papel de manutenção), que devolve só o necessário para
        // conferir o token pelo id; só esse papel tem leitura sem tenant.
        TenantSchema::enableRowLevelSecurity('personal_access_tokens');
        DB::statement("create policy internal_only on personal_access_tokens as restrictive for all to {$app}
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())");
        DB::statement('create policy token_lookup on personal_access_tokens as permissive for select to current_user using (true)');
        DB::unprepared(<<<'SQL'
            create or replace function api_token_find(p_id bigint)
                returns table (id bigint, tenant_id uuid, tokenable_id uuid, token varchar, abilities text, expires_at timestamptz)
                language sql stable security definer set search_path = public
                as $$
                    select t.id, t.tenant_id, t.tokenable_id, t.token, t.abilities, t.expires_at
                      from personal_access_tokens t
                     where t.id = p_id and t.tokenable_type = 'api_client'
                $$;
            revoke all on function api_token_find(bigint) from public;
            SQL);
        DB::statement("grant execute on function api_token_find(bigint) to {$app}");

        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('url', 500);
            $table->text('secret_encrypted');
            $table->jsonb('events');
            $table->boolean('is_active')->default(true);
            $table->integer('consecutive_failures')->default(0);
            $table->timestampTz('disabled_at')->nullable();
            $table->string('disabled_reason', 300)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("alter table webhook_endpoints add constraint webhook_endpoints_values_check check (
            jsonb_typeof(events) = 'array' and consecutive_failures >= 0 and url like 'http%')");
        $internal('webhook_endpoints');

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'webhook_endpoint_id', 'webhook_endpoints', cascade: true);
            // Sem chave estrangeira: a entrega sobrevive à limpeza da outbox.
            $table->uuid('outbox_message_id');
            $table->string('event_type', 80);
            $table->jsonb('payload');
            $table->string('status', 10)->default('pending');
            $table->integer('attempts')->default(0);
            $table->integer('response_status')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'webhook_endpoint_id', 'outbox_message_id']);
            $table->index(['tenant_id', 'status', 'next_attempt_at']);
        });
        DB::statement("alter table webhook_deliveries add constraint webhook_deliveries_status_check check (status in ('pending', 'delivered', 'failed') and attempts >= 0)");
        $internal('webhook_deliveries');

        Schema::create('csv_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            $table->string('kind', 12);
            $table->string('policy', 12)->default('valid_only');
            $table->string('status', 12)->default('validated');
            $table->string('original_name', 200);
            $table->char('file_hash', 64);
            $table->integer('rows_total')->default(0);
            $table->integer('rows_valid')->default(0);
            $table->integer('rows_invalid')->default(0);
            $table->integer('rows_applied')->default(0);
            $table->string('failure', 500)->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'created_at']);
        });
        DB::statement("alter table csv_imports add constraint csv_imports_values_check check (
            kind in ('customers', 'products', 'stock') and policy in ('all_or_none', 'valid_only')
            and status in ('validated', 'queued', 'processing', 'done', 'rejected', 'failed')
            and rows_total >= 0 and rows_valid >= 0 and rows_invalid >= 0 and rows_applied >= 0
            and rows_valid + rows_invalid = rows_total and rows_applied <= rows_valid)");
        $internal('csv_imports');

        Schema::create('csv_import_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'csv_import_id', 'csv_imports', cascade: true);
            $table->integer('line');
            $table->jsonb('data');
            $table->string('status', 10);
            $table->jsonb('errors')->default('[]');
            $table->uuid('entity_id')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'csv_import_id', 'line']);
        });
        DB::statement("alter table csv_import_rows add constraint csv_import_rows_status_check check (status in ('valid', 'invalid', 'applied', 'skipped'))");
        $internal('csv_import_rows');
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_import_rows');
        Schema::dropIfExists('csv_imports');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        DB::statement('drop function if exists api_token_find(bigint)');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('api_clients');
    }
};
