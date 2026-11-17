<?php

declare(strict_types=1);

namespace App\Models\Identity;

use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\Identity\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Filial do distribuidor.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property string|null $document
 * @property string|null $city
 * @property string|null $state
 * @property bool $is_headquarters
 * @property bool $is_active
 */
#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'document', 'city', 'state', 'is_headquarters', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
