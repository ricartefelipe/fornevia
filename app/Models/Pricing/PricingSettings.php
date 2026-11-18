<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração de preços do tenant: versão de preços (incrementada a cada
 * alteração que afete preços; compõe a chave do cache). Legível pelo
 * comprador; a margem mínima fica em PricingPolicy (interna).
 *
 * @property string $tenant_id
 * @property int $price_version
 */
class PricingSettings extends Model
{
    use BelongsToTenant;

    public const CREATED_AT = null;

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['price_version' => 'integer'];
    }
}
