<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Funções lidas pelas políticas de RLS. Os valores são definidos pela
 * aplicação com set_config(nome, valor, true), locais à transação.
 * Sem valor definido, as funções retornam NULL e nenhuma linha protegida
 * é visível (comportamento fail-closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            create or replace function app_current_tenant() returns uuid
                language sql stable parallel safe
                as $$ select nullif(current_setting('app.tenant_id', true), '')::uuid $$;

            create or replace function app_current_buyer() returns uuid
                language sql stable parallel safe
                as $$ select nullif(current_setting('app.buyer_company_id', true), '')::uuid $$;

            create or replace function app_current_user() returns uuid
                language sql stable parallel safe
                as $$ select nullif(current_setting('app.user_id', true), '')::uuid $$;

            create or replace function app_context_kind() returns text
                language sql stable parallel safe
                as $$ select nullif(current_setting('app.context_kind', true), '') $$;

            create or replace function app_sees_all_buyers() returns boolean
                language sql stable parallel safe
                as $$ select coalesce(app_context_kind() in ('staff', 'integration', 'system'), false) $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop function if exists app_sees_all_buyers();
            drop function if exists app_context_kind();
            drop function if exists app_current_user();
            drop function if exists app_current_buyer();
            drop function if exists app_current_tenant();
            SQL);
    }
};
