<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtro explícito por tenant nas consultas Eloquent.
 *
 * É a primeira camada de isolamento; a garantia definitiva vem das
 * políticas de RLS e das chaves estrangeiras compostas (tenant_id, id).
 * Sem contexto ativo a consulta falha em vez de retornar dados.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(CurrentContext::class)->tenantId();

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
