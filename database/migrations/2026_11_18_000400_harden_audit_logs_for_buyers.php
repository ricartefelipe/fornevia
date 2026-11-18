<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Auditoria do tenant no contexto de comprador e histórico da empresa:
 *
 * - buyer_company_id: coluna gerada a partir de data->>'buyer_company_id'
 *   (quando é um UUID), indexada. Comparação uuid = uuid é leakproof e pode
 *   usar o índice sob RLS, ao contrário de uma expressão sobre jsonb;
 * - leitura: somente equipe, integrações e sistema (o comprador não lê a
 *   auditoria pelo banco, nem as próprias ações registradas quando era da
 *   equipe);
 * - gravação no contexto de comprador: apenas como o próprio usuário e sem
 *   atribuir o registro a outra empresa.
 */
return new class extends Migration
{
    private const string UUID_PATTERN = '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$';

    public function up(): void
    {
        DB::statement("alter table audit_logs add column buyer_company_id uuid generated always as (
            case when (data ->> 'buyer_company_id') ~ '".self::UUID_PATTERN."' then (data ->> 'buyer_company_id')::uuid end
        ) stored");
        DB::statement('create index audit_logs_buyer_company_index on audit_logs (tenant_id, buyer_company_id, created_at) where buyer_company_id is not null');

        DB::statement('create policy buyer_read on audit_logs as restrictive for select using (app_sees_all_buyers())');
        DB::statement('create policy buyer_write on audit_logs as restrictive for insert
            with check (app_sees_all_buyers() or (
                actor_user_id = app_current_user()
                and (buyer_company_id is null or buyer_company_id = app_current_buyer())
            ))');
    }

    public function down(): void
    {
        DB::statement('drop policy if exists buyer_write on audit_logs');
        DB::statement('drop policy if exists buyer_read on audit_logs');
        DB::statement('drop index if exists audit_logs_buyer_company_index');
        DB::statement('alter table audit_logs drop column if exists buyer_company_id');
    }
};
