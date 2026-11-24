<?php

declare(strict_types=1);

namespace App\Models\Integrations;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Linha de importação: dados lidos, situação, erros por campo e o registro
 * criado ou alterado quando aplicada.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $csv_import_id
 * @property int $line
 * @property array<string, string> $data
 * @property string $status
 * @property list<string> $errors
 * @property string|null $entity_id
 */
class CsvImportRow extends Model
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
            'line' => 'integer',
            'data' => 'array',
            'errors' => 'array',
        ];
    }
}
