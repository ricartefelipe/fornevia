<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\TenantContextConflict;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Context;

/**
 * Executa trabalho dentro de uma transação com o contexto de tenant aplicado
 * ao PostgreSQL via set_config(..., true).
 *
 * Por serem locais à transação, as configurações deixam de existir no
 * COMMIT ou ROLLBACK, e a conexão volta ao estado sem contexto (no qual as
 * políticas de RLS não retornam linhas). Uma conexão reaproveitada por um
 * worker nunca herda o tenant da execução anterior.
 */
final readonly class TenantDatabase
{
    public function __construct(
        private DatabaseManager $db,
        private CurrentContext $current,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(ExecutionContext $context, Closure $callback, int $attempts = 1): mixed
    {
        $connection = $this->connection();

        if ($connection->transactionLevel() > 0) {
            $active = $this->current->get();

            if ($active === null || ! $active->equals($context)) {
                throw new TenantContextConflict(
                    'Transação aberta com contexto diferente do solicitado.'
                );
            }

            return $connection->transaction($callback);
        }

        return $connection->transaction(function () use ($connection, $context, $callback): mixed {
            $this->apply($connection, $context);
            $previous = $this->current->swap($context);
            $logKeys = ['context_kind', 'tenant_id', 'user_id', 'buyer_company_id', 'api_client_id'];
            $previousLog = Context::only($logKeys);
            Context::forget($logKeys);
            Context::add($context->toLogContext());

            try {
                return $callback();
            } finally {
                $this->current->swap($previous);
                Context::forget($logKeys);
                Context::add($previousLog);
            }
        }, $attempts);
    }

    /**
     * Valores efetivamente ativos na sessão do banco (diagnóstico e testes).
     *
     * @return array<string, string>
     */
    public function activeDatabaseSettings(): array
    {
        $row = $this->connection()->selectOne(
            "select coalesce(current_setting('app.context_kind', true), '') as context_kind,
                    coalesce(current_setting('app.user_id', true), '') as user_id,
                    coalesce(current_setting('app.tenant_id', true), '') as tenant_id,
                    coalesce(current_setting('app.buyer_company_id', true), '') as buyer_company_id"
        );

        return (array) $row;
    }

    private function apply(ConnectionInterface $connection, ExecutionContext $context): void
    {
        $settings = $context->databaseSettings();
        $sql = [];
        $bindings = [];

        foreach ($settings as $name => $value) {
            $sql[] = 'set_config(?, ?, true)';
            $bindings[] = $name;
            $bindings[] = $value;
        }

        $connection->select('select '.implode(', ', $sql), $bindings);
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection();
    }
}
