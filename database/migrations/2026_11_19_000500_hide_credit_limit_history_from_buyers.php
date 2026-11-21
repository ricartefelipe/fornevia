<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Histórico de alterações de limite (valores e justificativas internas):
 * invisível no contexto de comprador, inclusive para a própria empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create policy internal_only on credit_limit_changes as restrictive for all
            using (app_sees_all_buyers()) with check (app_sees_all_buyers())');
    }

    public function down(): void
    {
        DB::statement('drop policy if exists internal_only on credit_limit_changes');
    }
};
