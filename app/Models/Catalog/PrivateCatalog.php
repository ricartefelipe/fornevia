<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo privado: conjunto de produtos restritos visível a empresas ou grupos atribuídos.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 */
class PrivateCatalog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description', 'is_active'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
