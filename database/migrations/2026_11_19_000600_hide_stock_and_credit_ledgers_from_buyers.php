<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reservas, movimentações de estoque e dados de crédito são internos
 * (ADR-020): o contexto de comprador não os lê, nem os da própria empresa
 * (observações, motivos de cancelamento e de bloqueio, justificativas). O
 * portal mostra apenas o que os pedidos e o catálogo expõem; a confirmação
 * grava e consulta esses dados no contexto de sistema do mesmo usuário.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES = ['stock_reservations', 'stock_movements', 'credit_accounts', 'credit_movements', 'credit_exceptions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("create policy internal_read on {$table} as restrictive for select using (app_sees_all_buyers())");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("drop policy if exists internal_read on {$table}");
        }
    }
};
