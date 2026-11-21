<?php

declare(strict_types=1);

namespace App\Models\Buyers;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Grupo de clientes (segmentação para tabelas de preço e catálogos).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 */
class CustomerGroup extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'description'];
}
