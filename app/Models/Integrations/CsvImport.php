<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Importação CSV (RF-113): tipo, política de aplicação e contagens por
 * situação das linhas.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $policy
 * @property string $status
 * @property string $original_name
 * @property string $file_hash
 * @property int $rows_total
 * @property int $rows_valid
 * @property int $rows_invalid
 * @property int $rows_applied
 * @property string|null $failure
 * @property string|null $created_by_user_id
 * @property \Carbon\CarbonImmutable|null $confirmed_at
 * @property \Carbon\CarbonImmutable|null $finished_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CsvImportRow> $rows
 */
class CsvImport extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['*'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'rows_total' => 'integer',
            'rows_valid' => 'integer',
            'rows_invalid' => 'integer',
            'rows_applied' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<CsvImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(CsvImportRow::class)->orderBy('line');
    }
}
