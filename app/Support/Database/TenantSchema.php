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
     * Chave estrangeira composta que amarra também a empresa compradora:
     * (tenant_id, coluna, buyer_company_id) → (tenant_id, id, buyer_company_id).
     * As checagens de FK ignoram o RLS; sem a empresa na chave, uma linha da
     * empresa A poderia apontar para registro da empresa B do mesmo tenant.
     * A tabela referenciada precisa de companyKey().
     */
    public static function companyReference(
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

        $foreign = $table->foreign(['tenant_id', $column, 'buyer_company_id'], self::constraintName($table->getTable(), $column, 'company_fk'))
            ->references(['tenant_id', 'id', 'buyer_company_id'])
            ->on($on);
        $cascade ? $foreign->cascadeOnDelete() : $foreign->restrictOnDelete();

        $table->index(['tenant_id', $column]);
    }

    /**
     * unique (tenant_id, id, buyer_company_id): alvo de companyReference().
     */
    public static function companyKey(string $table): void
    {
        self::assertIdentifier($table);

        DB::statement(sprintf(
            'alter table %s add constraint %s unique (tenant_id, id, buyer_company_id)',
            $table,
            self::constraintName($table, 'buyer', 'unique'),
        ));
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
     * Tabelas mantidas pela equipe do distribuidor (configuração, cadastros,
     * catálogo, preços): contextos de comprador e de usuário só leem. As
     * políticas são restritivas e se somam às de tenant e de empresa.
     */
    public static function buyerReadOnly(string $table): void
    {
        self::assertIdentifier($table);

        DB::statement("create policy staff_insert on {$table} as restrictive for insert with check (app_sees_all_buyers())");
        DB::statement("create policy staff_update on {$table} as restrictive for update using (app_sees_all_buyers()) with check (app_sees_all_buyers())");
        DB::statement("create policy staff_delete on {$table} as restrictive for delete using (app_sees_all_buyers())");
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

    /**
     * Nome explícito de restrição: o PostgreSQL truncaria nomes acima de 63
     * caracteres, e nomes truncados podem colidir.
     */
    private static function constraintName(string $table, string $column, string $suffix): string
    {
        $name = "{$table}_{$column}_{$suffix}";
        self::assertIdentifier($name);

        if (strlen($name) > 63) {
            throw new InvalidArgumentException("Nome de restrição acima de 63 caracteres: {$name}");
        }

        return $name;
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/D', $identifier) !== 1) {
            throw new InvalidArgumentException("Identificador SQL inválido: {$identifier}");
        }
    }
}
