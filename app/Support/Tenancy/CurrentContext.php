<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\TenantContextMissing;

/**
 * Contexto ativo da unidade de trabalho corrente.
 *
 * Registrado como "scoped" no container: é descartado ao fim de cada
 * requisição e antes de cada job pelo worker. Só é alterado por
 * TenantDatabase, que mantém o contexto vinculado à transação aberta.
 */
final class CurrentContext
{
    private ?ExecutionContext $context = null;

    public function get(): ?ExecutionContext
    {
        return $this->context;
    }

    public function require(): ExecutionContext
    {
        return $this->context ?? throw new TenantContextMissing('Nenhum contexto de execução ativo.');
    }

    public function tenantId(): string
    {
        $tenantId = $this->context?->tenantId;

        if ($tenantId === null) {
            throw new TenantContextMissing('Nenhum tenant ativo no contexto de execução.');
        }

        return $tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->context?->tenantId !== null;
    }

    /**
     * @internal Usado exclusivamente por TenantDatabase.
     */
    public function swap(?ExecutionContext $context): ?ExecutionContext
    {
        $previous = $this->context;
        $this->context = $context;

        return $previous;
    }
}
