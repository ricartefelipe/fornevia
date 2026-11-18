<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Remove uma mídia de produto. O registro sai na transação; o arquivo só é
 * apagado depois do commit (um rollback preserva arquivo e registro).
 */
final readonly class DeleteProductMedia
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(ProductMedia $media): void
    {
        $this->access->authorize(Permission::CatalogManage);

        DB::transaction(function () use ($media): void {
            $locked = ProductMedia::query()->lockForUpdate()->findOrFail($media->id);
            $product = Product::query()->findOrFail($locked->product_id);

            if ($product->status === ProductStatus::Archived) {
                throw CatalogItemArchived::product();
            }

            $disk = $locked->disk;
            $path = $locked->path;
            $locked->delete();

            $this->audit->record('product_media.deleted', $locked, [
                'product_id' => $product->id,
                'kind' => $locked->kind->value,
                'original_name' => $locked->original_name,
                'sha256' => $locked->sha256,
            ]);

            DB::afterCommit(static fn () => Storage::disk($disk)->delete($path));
        });
    }
}
