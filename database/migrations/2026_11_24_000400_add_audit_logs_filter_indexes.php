<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices para os filtros da tela de auditoria por autor e por registro
 * afetado (sem o tipo), com o tenant e na ordem de exibição. O filtro por
 * ação usa o índice por tenant e data, já existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index audit_logs_tenant_actor_created_index on audit_logs (tenant_id, actor_user_id, created_at desc) where actor_user_id is not null');
        DB::statement('create index audit_logs_tenant_subject_created_index on audit_logs (tenant_id, subject_id, created_at desc) where subject_id is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists audit_logs_tenant_subject_created_index');
        DB::statement('drop index if exists audit_logs_tenant_actor_created_index');
    }
};
