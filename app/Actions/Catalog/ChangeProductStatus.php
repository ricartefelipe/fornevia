<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\Exceptions\InvalidProductStatusTransition;
use App\Domain\Catalog\Exceptions\ProductRequiresActiveSku;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Altera a situação comercial de um produto.
 *
 * Transições: rascunho → ativo, inativo ou arquivado; ativo ⇄ inativo;
 * ativo ou inativo → arquivado. Ativar exige ao menos um SKU ativo.
 * Arquivar registra archived_at e arquiva todos os SKUs; o arquivamento é
 * definitivo (o produto permanece apenas como histórico).
 *
 * Ordem de locks: produto e, depois, SKUs (a mesma de SaveSku).
 */
final readonly class ChangeProductStatus
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(Product $product, ProductStatus $target): Product
    {
        $this->access->authorize(Permission::CatalogManage);

        return DB::transaction(function () use ($product, $target): Product {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $from = $locked->status;

            if ($from === $target) {
                return $locked;
            }

            if (! in_array($target, self::allowedFrom($from), true)) {
                throw InvalidProductStatusTransition::between($from->value, $target->value, $from->label(), $target->label());
            }

            $skus = Sku::query()->where('product_id', $locked->id)->orderBy('code')->lockForUpdate()->get(['id', 'code', 'status']);

            if ($target === ProductStatus::Active && ! $skus->contains(static fn (Sku $sku): bool => $sku->status === SkuStatus::Active)) {
                throw ProductRequiresActiveSku::toActivate();
            }

            $now = $this->clock->now();
            $archivedSkus = [];

            if ($target === ProductStatus::Archived) {
                $archivedSkus = $skus->reject(static fn (Sku $sku): bool => $sku->status === SkuStatus::Archived)
                    ->map(static fn (Sku $sku): string => $sku->code)
                    ->values()
                    ->all();

                Sku::query()
                    ->where('product_id', $locked->id)
                    ->where('status', '<>', SkuStatus::Archived->value)
                    ->update(['status' => SkuStatus::Archived->value, 'updated_at' => $now]);
            }

            $locked->forceFill([
                'status' => $target,
                'archived_at' => $target === ProductStatus::Archived ? $now : null,
            ])->save();

            $this->audit->record('product.status_changed', $locked, array_filter([
                'from' => $from->value,
                'to' => $target->value,
                'archived_skus' => $archivedSkus,
            ], static fn (mixed $value): bool => $value !== []));

            return $locked;
        });
    }

    /**
     * @return list<ProductStatus>
     */
    public static function allowedFrom(ProductStatus $status): array
    {
        return match ($status) {
            ProductStatus::Draft => [ProductStatus::Active, ProductStatus::Inactive, ProductStatus::Archived],
            ProductStatus::Active => [ProductStatus::Inactive, ProductStatus::Archived],
            ProductStatus::Inactive => [ProductStatus::Active, ProductStatus::Archived],
            ProductStatus::Archived => [],
        };
    }
}
