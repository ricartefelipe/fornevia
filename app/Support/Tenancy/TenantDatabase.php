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
    /** Chave oculta do Context com o distribuidor da requisição (métricas). */
    public const string METRICS_TENANT_KEY = 'metrics_tenant_id';

    private const array LOG_KEYS = ['context_kind', 'tenant_id', 'user_id', 'buyer_company_id', 'api_client_id'];

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

            if ($active !== null && $active->equals($context)) {
                return $connection->transaction($callback);
            }

            if ($active !== null && $this->canElevate($active, $context)) {
                return $this->elevate($connection, $active, $context, $callback);
            }

            throw new TenantContextConflict(
                'Transação aberta com contexto diferente do solicitado.'
            );
        }

        return $connection->transaction(function () use ($connection, $context, $callback): mixed {
            $this->apply($connection, $context);
            $previous = $this->current->swap($context);

            // Distribuidor da requisição para as métricas por distribuidor
            // (oculto: não vai aos logs e permanece depois da transação).
            if ($context->tenantId !== null && ! Context::hasHidden(self::METRICS_TENANT_KEY)) {
                Context::addHidden(self::METRICS_TENANT_KEY, $context->tenantId);
            }
            $previousLog = Context::only(self::LOG_KEYS);
            Context::forget(self::LOG_KEYS);
            Context::add($context->toLogContext());

            try {
                return $callback();
            } finally {
                $this->current->swap($previous);
                Context::forget(self::LOG_KEYS);
                Context::add($previousLog);
            }
        }, $attempts);
    }

    /**
     * Executa no contexto de sistema do MESMO usuário (ADR-014): gravação em
     * tabelas que os contextos de comprador e de usuário só leem (estoque,
     * crédito, pedidos, níveis e decisões de aprovação, orçamento). Quem
     * chama já autorizou a operação no contexto original; dentro do
     * callback as consultas enxergam todas as empresas do tenant e precisam
     * filtrar a empresa explicitamente. O cliente da API eleva da mesma forma,
     * para o sistema do próprio tenant, e continua identificado como autor.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asSystem(Closure $callback): mixed
    {
        $active = $this->current->require();

        return $this->run(ExecutionContext::system($active->requireTenantId(), $active->userId, $active->apiClientId), $callback);
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

    /**
     * Elevação controlada (ADR-014): uma unidade de trabalho iniciada por um
     * usuário pode entrar temporariamente em outro contexto do MESMO usuário
     * (seleção de contexto, aceite de convite, onboarding). Contextos sem
     * usuário (jobs, sistema) nunca trocam de contexto, e não há troca para
     * contexto de outro usuário.
     */
    private function canElevate(ExecutionContext $active, ExecutionContext $requested): bool
    {
        if ($active->userId !== null) {
            return $requested->userId === $active->userId;
        }

        return $active->apiClientId !== null
            && $requested->kind === ContextKind::System
            && $requested->apiClientId === $active->apiClientId
            && $requested->tenantId === $active->tenantId;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function elevate(ConnectionInterface $connection, ExecutionContext $active, ExecutionContext $context, Closure $callback): mixed
    {
        // Em savepoint: se falhar, o ROLLBACK TO SAVEPOINT desfaz também as
        // configurações; em caso de sucesso, o contexto anterior é reaplicado.
        return $connection->transaction(function () use ($connection, $active, $context, $callback): mixed {
            $this->apply($connection, $context);
            $previous = $this->current->swap($context);

            try {
                $result = $callback();
            } finally {
                $this->current->swap($previous);
            }

            $this->apply($connection, $active);

            return $result;
        });
    }

    private function apply(ConnectionInterface $connection, ExecutionContext $context): void
    {
        $sql = [];
        $bindings = [];

        foreach ($context->databaseSettings() as $name => $value) {
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
