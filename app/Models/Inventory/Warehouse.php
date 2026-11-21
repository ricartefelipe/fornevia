<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\Identity\Branch;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Depósito de uma filial. allows_sales define se o saldo atende pedidos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $branch_id
 * @property string $code
 * @property string $name
 * @property int $priority
 * @property bool $allows_sales
 * @property bool $is_active
 */
class Warehouse extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'priority', 'allows_sales', 'is_active'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'allows_sales' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
