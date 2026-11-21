<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Unidade de medida e casas decimais aceitas nas quantidades.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property int $decimals
 */
class UnitOfMeasure extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'units_of_measure';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'decimals'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'decimals' => 'integer',
        ];
    }
}
