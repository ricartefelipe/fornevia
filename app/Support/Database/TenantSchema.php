<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Convenções de schema para tabelas pertencentes a um tenant.
 *
 * - tenant_id obrigatório, referenciando organizations;
 * - unique (tenant_id, id), alvo das chaves estrangeiras compostas;
 * - referências entre tabelas do tenant usam (tenant_id, coluna), o que
 *   torna impossível, no próprio banco, apontar para registro de outro tenant;
 * - RLS habilitada e forçada (inclusive para o dono da tabela).
 */
final class TenantSchema
{
    public static function tenant(Blueprint $table): void
    {
        $table->uuid('tenant_id');
        $table->foreign('tenant_id')->references('id')->on('organizations')->restrictOnDelete();
        $table->unique(['tenant_id', 'id']);
    }

    /**
     * Chave estrangeira composta para outra tabela do mesmo tenant.
     */
    public static function reference(
        Blueprint $table,
        string $column,
        string $on,
        bool $nullable = false,
        bool $cascade = false,
    ): void {
        $definition = $table->uuid($column);

        if ($nullable) {
            $definition->nullable();
        }

        $foreign = $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($on);
        $cascade ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();

        $table->index(['tenant_id', $column]);
    }

    /**
     * Habilita RLS por tenant e, opcionalmente, por empresa compradora.
     *
     * $buyerScope:
     *  - null: apenas isolamento por tenant;
     *  - 'required': linha pertence a uma empresa compradora (coluna NOT NULL);
     *  - 'nullable': linhas sem empresa são visíveis a todos do tenant.
     */
    public static function enableRowLevelSecurity(
        string $table,
        ?string $buyerScope = null,
        string $buyerColumn = 'buyer_company_id',
    ): void {
        self::assertIdentifier($table);
        self::assertIdentifier($buyerColumn);

        DB::statement("alter table {$table} enable row level security");
        DB::statement("alter table {$table} force row level security");
        DB::statement(
            "create policy tenant_isolation on {$table} as permissive for all
             using (tenant_id = app_current_tenant())
             with check (tenant_id = app_current_tenant())"
        );

        if ($buyerScope === null) {
            return;
        }

        $condition = match ($buyerScope) {
            'required' => "app_sees_all_buyers() or {$buyerColumn} = app_current_buyer()",
            'nullable' => "app_sees_all_buyers() or {$buyerColumn} is null or {$buyerColumn} = app_current_buyer()",
            default => throw new InvalidArgumentException("Escopo de comprador inválido: {$buyerScope}"),
        };

        DB::statement(
            "create policy buyer_isolation on {$table} as restrictive for all
             using ({$condition}) with check ({$condition})"
        );
    }

    /**
     * Tabelas de histórico: a aplicação apenas insere e lê.
     * Correções são feitas por lançamentos compensatórios.
     */
    public static function appendOnly(string $table): void
    {
        self::assertIdentifier($table);
        $app = self::appRole();

        DB::statement("revoke update, delete, truncate on {$table} from {$app}");
    }

    /**
     * Projeções mantidas exclusivamente por triggers de ledger: a aplicação
     * lê e bloqueia (SELECT ... FOR UPDATE exige UPDATE em ao menos uma
     * coluna), mas não altera saldos diretamente.
     *
     * @param  list<string>  $updatableColumns
     */
    public static function projection(string $table, array $updatableColumns = ['updated_at']): void
    {
        self::assertIdentifier($table);
        $app = self::appRole();

        DB::statement("revoke insert, update, delete, truncate on {$table} from {$app}");

        if ($updatableColumns !== []) {
            foreach ($updatableColumns as $column) {
                self::assertIdentifier($column);
            }

            DB::statement(sprintf('grant update (%s) on %s to %s', implode(', ', $updatableColumns), $table, $app));
        }
    }

    public static function appRole(): string
    {
        $role = (string) config('database.roles.app');
        self::assertIdentifier($role);

        return $role;
    }

    public static function relayRole(): string
    {
        $role = (string) config('database.roles.relay');
        self::assertIdentifier($role);

        return $role;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException("Identificador SQL inválido: {$identifier}");
        }
    }
}
