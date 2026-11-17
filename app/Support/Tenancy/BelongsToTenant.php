<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Identity\Organization;
use App\Support\Tenancy\Exceptions\CrossTenantReference;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelos pertencentes a um tenant.
 *
 * - aplica TenantScope em todas as consultas;
 * - preenche tenant_id a partir do contexto ativo na criação;
 * - rejeita gravação com tenant_id diferente do contexto;
 * - impede alteração de tenant_id após a criação.
 *
 * tenant_id nunca deve constar em $fillable.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (Model $model): void {
            $tenantId = app(CurrentContext::class)->tenantId();
            $current = $model->getAttribute('tenant_id');

            if ($current === null) {
                $model->setAttribute('tenant_id', $tenantId);

                return;
            }

            if ($current !== $tenantId) {
                throw new CrossTenantReference('Tentativa de criar registro em outro tenant.');
            }
        });

        static::updating(static function (Model $model): void {
            if ($model->isDirty('tenant_id')) {
                throw new CrossTenantReference('O tenant de um registro não pode ser alterado.');
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'tenant_id');
    }
}
