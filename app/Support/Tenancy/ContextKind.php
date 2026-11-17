<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

/**
 * Tipo de contexto em que uma operação é executada.
 *
 * O valor é propagado ao PostgreSQL (app.context_kind) e participa das
 * políticas de RLS: sem contexto definido, nenhuma linha de tabela
 * protegida é visível.
 */
enum ContextKind: string
{
    /** Usuário autenticado sem organização selecionada. */
    case User = 'user';

    /** Equipe do distribuidor (tenant). */
    case Staff = 'staff';

    /** Usuário de uma empresa compradora dentro do tenant. */
    case Buyer = 'buyer';

    /** Integração autenticada por token (ApiClient). */
    case Integration = 'integration';

    /** Processamento interno (jobs, scheduler, importações). */
    case System = 'system';

    public function hasTenant(): bool
    {
        return $this !== self::User;
    }

    /**
     * Contextos que enxergam dados de todas as empresas compradoras do tenant.
     */
    public function seesAllBuyers(): bool
    {
        return $this === self::Staff || $this === self::Integration || $this === self::System;
    }
}
