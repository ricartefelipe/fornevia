<?php

declare(strict_types=1);

namespace App\Models\Pricing;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuração de preços do tenant: margem mínima padrão e versão de
 * preços (incrementada a cada alteração que afete preços; compõe a chave
 * do cache).
 *
 * @property string $tenant_id
 * @property int $min_margin_bps
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
        return ['min_margin_bps' => 'integer', 'price_version' => 'integer'];
    }
}
