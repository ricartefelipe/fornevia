<?php

declare(strict_types=1);

namespace App\Models\Ordering;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lista de compra (pessoal ou compartilhada com a empresa).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $buyer_company_id
 * @property string $membership_id
 * @property string $name
 * @property bool $is_shared
 */
class ShoppingList extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['name', 'is_shared'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_shared' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ShoppingListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShoppingListItem::class);
    }
}
