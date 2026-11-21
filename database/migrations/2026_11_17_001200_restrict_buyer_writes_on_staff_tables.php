<?php

declare(strict_types=1);

use App\Support\Database\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defesa em profundidade dentro do tenant: tabelas mantidas pela equipe
 * do distribuidor são somente leitura para contextos de comprador (e de
 * usuário), mesmo por SQL direto.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['roles', 'branches', 'customer_groups', 'buyer_companies'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            TenantSchema::buyerReadOnly($table);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            foreach (['staff_insert', 'staff_update', 'staff_delete'] as $policy) {
                DB::statement("drop policy if exists {$policy} on {$table}");
            }
        }
    }
};
