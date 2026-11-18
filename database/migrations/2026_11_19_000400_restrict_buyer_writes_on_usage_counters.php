<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contador de uso do plano (pedidos confirmados por mês): o comprador não o
 * altera diretamente. A confirmação do pedido consome a cota no contexto de
 * sistema do mesmo usuário (ADR-014), na mesma transação.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantSchema::buyerReadOnly('tenant_usage_counters');
    }

    public function down(): void
    {
        foreach (['staff_insert', 'staff_update', 'staff_delete'] as $policy) {
            DB::statement("drop policy if exists {$policy} on tenant_usage_counters");
        }
    }
};
