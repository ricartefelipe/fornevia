<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consumo idempotente da outbox e notificações (ADR-008, ADR-026):
 *
 * - outbox_consumptions: um registro por mensagem e consumidor, gravado na
 *   transação do efeito; a repetição da mensagem não repete o efeito;
 * - o relay apaga mensagens publicadas além do prazo de retenção (as falhas
 *   e os consumos vão junto);
 * - user_notifications: notificação no sistema, uma por mensagem e
 *   associação; cada pessoa só vê as próprias, gravadas no contexto de
 *   sistema; só a data de leitura é alterável;
 * - notification_settings: preferência de e-mail por associação.
 */
return new class extends Migration
{
    public function up(): void
    {
        $app = TenantSchema::appRole();
        $relay = TenantSchema::relayRole();

        Schema::create('outbox_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'outbox_message_id', 'outbox_messages', cascade: true);
            $table->string('consumer', 80);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'outbox_message_id', 'consumer']);
        });
        TenantSchema::enableRowLevelSecurity('outbox_consumptions');
        TenantSchema::appendOnly('outbox_consumptions');
        DB::statement("create policy internal_only on outbox_consumptions as restrictive for all to {$app}
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())");

        DB::statement("grant delete on outbox_messages to {$relay}");
        DB::statement('create index outbox_messages_published_index on outbox_messages (published_at) where status = \'published\'');

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'membership_id', 'memberships', cascade: true);
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Sem chave estrangeira: a notificação sobrevive à limpeza da outbox.
            $table->uuid('outbox_message_id')->nullable();
            $table->string('kind', 60);
            $table->string('title', 160);
            $table->string('body', 500);
            $table->string('url', 500);
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'outbox_message_id', 'membership_id']);
            $table->index(['tenant_id', 'membership_id', 'created_at']);
        });
        DB::statement("alter table user_notifications add constraint user_notifications_url_check check (url like '/%' and url not like '//%')");
        TenantSchema::enableRowLevelSecurity('user_notifications');
        self::personal('user_notifications');
        DB::statement("revoke update, truncate on user_notifications from {$app}");
        DB::statement("grant update (read_at) on user_notifications to {$app}");

        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            TenantSchema::tenant($table);
            TenantSchema::reference($table, 'membership_id', 'memberships', cascade: true);
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('email_enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'membership_id']);
        });
        TenantSchema::enableRowLevelSecurity('notification_settings');
        self::personal('notification_settings', ownWrites: true);
        DB::statement("revoke delete, truncate on notification_settings from {$app}");

        // A pessoa da linha é a da associação: ninguém grava preferência ou
        // notificação em nome de outra associação.
        DB::unprepared(<<<'SQL'
            create or replace function personal_row_owner_guard() returns trigger
                language plpgsql
                as $$
                begin
                    if not exists (
                        select 1 from memberships m
                         where m.id = new.membership_id and m.tenant_id = new.tenant_id and m.user_id = new.user_id
                    ) then
                        raise exception '%: a pessoa não corresponde à associação', tg_table_name
                            using errcode = 'check_violation';
                    end if;

                    return new;
                end
                $$;

            create trigger user_notifications_owner_guard before insert or update of membership_id, user_id on user_notifications
                for each row execute function personal_row_owner_guard();
            create trigger notification_settings_owner_guard before insert or update of membership_id, user_id on notification_settings
                for each row execute function personal_row_owner_guard();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
        Schema::dropIfExists('user_notifications');
        DB::statement('drop function if exists personal_row_owner_guard()');
        DB::statement('drop index if exists outbox_messages_published_index');
        DB::statement('revoke delete on outbox_messages from '.TenantSchema::relayRole());
        Schema::dropIfExists('outbox_consumptions');
    }

    /**
     * Linhas pessoais: no contexto de sistema, todas do tenant; fora dele,
     * só as da própria pessoa. Criar e apagar só no contexto de sistema,
     * salvo $ownWrites (a própria pessoa grava a sua preferência).
     */
    private static function personal(string $table, bool $ownWrites = false): void
    {
        $own = "(app_context_kind() = 'system' or user_id = app_current_user())";
        $system = "(app_context_kind() = 'system')";

        DB::statement("create policy own_rows on {$table} as restrictive for all using {$own} with check {$own}");

        if (! $ownWrites) {
            DB::statement("create policy system_inserts on {$table} as restrictive for insert with check {$system}");
            DB::statement("create policy system_deletes on {$table} as restrictive for delete using {$system}");
        }
    }
};
