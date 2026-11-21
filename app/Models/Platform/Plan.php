<?php

declare(strict_types=1);

namespace App\Models\Platform;

use App\Enums\Platform\PlatformModule;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Plano da plataforma. Preços e limites são hipóteses de produto
 * configuráveis pelo administrador da plataforma.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $monthly_price_cents
 * @property int $setup_fee_cents
 * @property int|null $max_staff_users
 * @property int|null $max_branches
 * @property int|null $max_orders_per_month
 * @property list<string> $modules
 * @property int $trial_days
 * @property bool $is_public
 * @property bool $is_active
 * @property int $sort_order
 */
class Plan extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'description', 'monthly_price_cents', 'setup_fee_cents', 'max_staff_users',
        'max_branches', 'max_orders_per_month', 'modules', 'trial_days', 'is_public', 'is_active', 'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_price_cents' => 'integer',
            'setup_fee_cents' => 'integer',
            'max_staff_users' => 'integer',
            'max_branches' => 'integer',
            'max_orders_per_month' => 'integer',
            'modules' => 'array',
            'trial_days' => 'integer',
            'is_public' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function includesModule(PlatformModule $module): bool
    {
        return in_array($module->value, $this->modules, true);
    }
}
