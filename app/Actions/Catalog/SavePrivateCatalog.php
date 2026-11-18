<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Catalog\Support\CatalogText;
use App\Data\Catalog\PrivateCatalogData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Enums\Identity\Permission;
use App\Models\Catalog\PrivateCatalog;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera um catálogo privado (código único no tenant). Um
 * catálogo inativo deixa de liberar seus produtos restritos aos compradores.
 */
final readonly class SavePrivateCatalog
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(?PrivateCatalog $catalog, PrivateCatalogData $data): PrivateCatalog
    {
        $this->access->authorize(Permission::CatalogManage);

        $code = mb_strtoupper(trim($data->code));

        if (preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/D', $code) !== 1) {
            throw InvalidCatalogData::field('code', 'O código deve ter até 30 caracteres: letras, números, hífen ou sublinhado.');
        }

        $name = CatalogText::required($data->name, 'name', 120, 'Informe o nome do catálogo (até 120 caracteres).');
        $description = CatalogText::multiline($data->description, 'description', 2000, 'A descrição deve ter até 2.000 caracteres.');

        try {
            return DB::transaction(function () use ($catalog, $data, $code, $name, $description): PrivateCatalog {
                $catalog = $catalog !== null
                    ? PrivateCatalog::query()->lockForUpdate()->findOrFail($catalog->id)
                    : new PrivateCatalog;

                $duplicate = PrivateCatalog::query()
                    ->where('code', $code)
                    ->when($catalog->exists, static fn ($query) => $query->whereKeyNot($catalog->id))
                    ->exists();

                if ($duplicate) {
                    throw self::codeInUse($code);
                }

                $creating = ! $catalog->exists;
                $before = $creating ? null : self::snapshot($catalog);
                $catalog->forceFill([
                    'code' => $code,
                    'name' => $name,
                    'description' => $description,
                    'is_active' => $data->isActive,
                ]);

                if (! $creating && ! $catalog->isDirty()) {
                    return $catalog;
                }

                $catalog->save();

                $this->audit->record($creating ? 'private_catalog.created' : 'private_catalog.updated', $catalog, [
                    'before' => $before,
                    'after' => self::snapshot($catalog),
                ]);

                return $catalog;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::codeInUse($code);
        }
    }

    private static function codeInUse(string $code): CatalogIdentifierInUse
    {
        return CatalogIdentifierInUse::for('code', $code, "Já existe um catálogo privado com o código {$code}.");
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(PrivateCatalog $catalog): array
    {
        return ['code' => $catalog->code, 'name' => $catalog->name, 'is_active' => $catalog->is_active];
    }
}
