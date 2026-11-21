<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\Exceptions\ProductHasHistory;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Catalog\Sku;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Exclui um produto sem histórico comercial (cadastro feito por engano).
 *
 * A exclusão é tentada em savepoint: se o produto ou um de seus SKUs for
 * referenciado por custos, preços ou documentos comerciais, o banco recusa
 * a remoção por chave estrangeira (SQLSTATE 23503, ou 23001 nas chaves
 * declaradas ON DELETE RESTRICT), o savepoint é desfeito e a recusa vira
 * ProductHasHistory, com a recomendação de arquivar (RN-022). Os arquivos
 * de mídia só são apagados após o commit.
 */
final readonly class DeleteProduct
{
    /** foreign_key_violation e restrict_violation */
    private const array REFERENCED_ROW_STATES = ['23503', '23001'];

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(Product $product): void
    {
        $this->access->authorize(Permission::CatalogManage);

        DB::transaction(function () use ($product): void {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $skuCodes = Sku::query()->where('product_id', $locked->id)->orderBy('code')->lockForUpdate()->pluck('code')->all();
            $files = ProductMedia::query()->where('product_id', $locked->id)->get(['id', 'disk', 'path'])
                ->map(static fn (ProductMedia $media): array => ['disk' => $media->disk, 'path' => $media->path])
                ->all();

            try {
                DB::transaction(static function () use ($locked): void {
                    Sku::query()->where('product_id', $locked->id)->delete();
                    $locked->delete();
                });
            } catch (QueryException $e) {
                if (in_array(self::sqlState($e), self::REFERENCED_ROW_STATES, true)) {
                    throw ProductHasHistory::create();
                }

                throw $e;
            }

            $this->audit->record('product.deleted', $locked, [
                'name' => $locked->name,
                'slug' => $locked->slug,
                'skus' => $skuCodes,
                'media_files' => count($files),
            ]);

            DB::afterCommit(static function () use ($files): void {
                foreach ($files as $file) {
                    Storage::disk($file['disk'])->delete($file['path']);
                }
            });
        });
    }

    private static function sqlState(QueryException $e): string
    {
        $state = $e->errorInfo[0] ?? $e->getCode();

        return is_scalar($state) ? (string) $state : '';
    }
}
