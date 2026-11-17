<?php

declare(strict_types=1);

use App\Models\Identity\Organization;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Tests\TestCase;

/*
| Unit: regras puras, sem Laravel, banco, Redis ou rede.
| Feature/Integration: aplicação + PostgreSQL real.
*/
pest()->extend(TestCase::class)->in('Feature', 'Integration');

/**
 * Executa o callback no contexto de sistema do tenant (transação com RLS).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asTenant(Organization|string $tenant, Closure $callback): mixed
{
    $tenantId = $tenant instanceof Organization ? $tenant->id : $tenant;

    return app(TenantDatabase::class)->run(ExecutionContext::system($tenantId), $callback);
}

/**
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function inContext(ExecutionContext $context, Closure $callback): mixed
{
    return app(TenantDatabase::class)->run($context, $callback);
}
