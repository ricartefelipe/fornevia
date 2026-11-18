<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Política comercial interna do tenant: margem mínima padrão (pontos-base)
 * usada na avaliação de descontos. Invisível ao comprador (RLS
 * internal_only), separada de pricing_settings, que o portal lê.
 *
 * @property string $tenant_id
 * @property int $min_margin_bps
 */
class PricingPolicy extends Model
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
        return ['min_margin_bps' => 'integer'];
    }
}
