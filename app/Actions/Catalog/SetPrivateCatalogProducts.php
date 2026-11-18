<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Enums\Identity\Permission;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogProduct;
use App\Models\Catalog\Product;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Define o conjunto de produtos de um catálogo privado. Todos os produtos
 * precisam pertencer ao tenant.
 *
 * - handle(): sincronização com a lista completa (inclui os ausentes e
 *   remove os que saíram);
 * - change(): inclusões e remoções pontuais aplicadas sobre o conteúdo lido
 *   sob o lock do catálogo, sem desfazer alterações concorrentes.
 */
final readonly class SetPrivateCatalogProducts
{
    public const int MAX_PRODUCTS = 5000;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  list<string>  $productIds
     * @return list<string> produtos do catálogo após a alteração
     */
    public function handle(PrivateCatalog $catalog, array $productIds): array
    {
        $this->access->authorize(Permission::CatalogManage);
        $ids = self::ids($productIds);

        return $this->apply($catalog, $ids, static fn (array $current): array => $ids);
    }

    /**
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return list<string> produtos do catálogo após a alteração
     */
    public function change(PrivateCatalog $catalog, array $add = [], array $remove = []): array
    {
        $this->access->authorize(Permission::CatalogManage);
        $add = self::ids($add);
        $remove = self::ids($remove);

        return $this->apply($catalog, $add, static fn (array $current): array => array_values(array_diff(
            array_values(array_unique([...$current, ...$add])),
            $remove,
        )));
    }

    /**
     * @param  list<string>  $mustExist
     * @param  callable(list<string>): list<string>  $target
     * @return list<string>
     */
    private function apply(PrivateCatalog $catalog, array $mustExist, callable $target): array
    {
        return DB::transaction(function () use ($catalog, $mustExist, $target): array {
            $locked = PrivateCatalog::query()->lockForUpdate()->findOrFail($catalog->id);

            $found = $mustExist === [] ? [] : Product::query()->whereKey($mustExist)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all();
            $missing = array_values(array_diff($mustExist, $found));

            if ($missing !== []) {
                throw (new ModelNotFoundException)->setModel(Product::class, $missing);
            }

            $current = array_values(PrivateCatalogProduct::query()
                ->where('private_catalog_id', $locked->id)
                ->pluck('product_id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all());

            $ids = $target($current);

            if (count($ids) > self::MAX_PRODUCTS) {
                throw InvalidCatalogData::field('products', 'Um catálogo privado aceita até '.self::MAX_PRODUCTS.' produtos.');
            }

            $added = array_values(array_diff($ids, $current));
            $removed = array_values(array_diff($current, $ids));

            if ($removed !== []) {
                PrivateCatalogProduct::query()
                    ->where('private_catalog_id', $locked->id)
                    ->whereIn('product_id', $removed)
                    ->delete();
            }

            foreach ($added as $productId) {
                $entry = new PrivateCatalogProduct;
                $entry->forceFill(['private_catalog_id' => $locked->id, 'product_id' => $productId])->save();
            }

            if ($added !== [] || $removed !== []) {
                $this->audit->record('private_catalog.products_updated', $locked, [
                    'added' => $added,
                    'removed' => $removed,
                ]);
            }

            return $ids;
        });
    }

    /**
     * @param  list<mixed>  $productIds
     * @return list<string>
     */
    private static function ids(array $productIds): array
    {
        if (count($productIds) > self::MAX_PRODUCTS) {
            throw InvalidCatalogData::field('products', 'Um catálogo privado aceita até '.self::MAX_PRODUCTS.' produtos.');
        }

        return array_values(array_unique(array_map(
            static fn (mixed $id): string => UntrustedId::uuid($id, Product::class),
            $productIds,
        )));
    }
}
