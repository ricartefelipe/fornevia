<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CNPJ alfanumérico (Receita Federal, a partir de julho de 2026): 12
 * caracteres de raiz e ordem (0-9 e A-Z, maiúsculas) e 2 dígitos
 * verificadores numéricos. Os CNPJs numéricos continuam válidos.
 */
return new class extends Migration
{
    private const array TABLES = ['organizations', 'buyer_companies'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("alter table {$table} drop constraint {$table}_document_check");
            DB::statement("alter table {$table} add constraint {$table}_document_check check (document ~ '^[0-9A-Z]{12}[0-9]{2}$')");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("alter table {$table} drop constraint {$table}_document_check");
            DB::statement("alter table {$table} add constraint {$table}_document_check check (document ~ '^[0-9]{14}$') not valid");
        }
    }
};
