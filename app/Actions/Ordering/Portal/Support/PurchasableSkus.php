<?php

declare(strict_types=1);

namespace App\Actions\Ordering\Portal\Support;

use App\Enums\Catalog\SkuStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Services\Catalog\BuyerCatalogScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * SKUs que a empresa compradora pode comprar: ativos, de produto ativo e
 * visível a ela (público ou em catálogo privado atribuído à empresa ou ao
 * grupo). A RLS já oculta o restante; o critério é repetido na consulta
 * (BuyerCatalogScope) como defesa em profundidade.
 */
final readonly class PurchasableSkus
{
    public function __construct(private BuyerCatalogScope $scope) {}

    public function find(BuyerCompany $company, string $skuId): ?Sku
    {
        return $this->query($company)->whereKey($skuId)->first();
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, Sku> por id
     */
    public function byIds(BuyerCompany $company, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->query($company)->whereIn('skus.id', array_values(array_unique($ids)))->get()->keyBy('id')->all();
    }

    /**
     * @param  list<string>  $codes  códigos já em maiúsculas
     * @return array<string, Sku> por código
     */
    public function byCodes(BuyerCompany $company, array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        return $this->query($company)->whereIn('skus.code', array_values(array_unique($codes)))->get()->keyBy('code')->all();
    }

    /**
     * @return Builder<Sku>
     */
    private function query(BuyerCompany $company): Builder
    {
        return Sku::query()
            ->with(['product', 'salesUnit'])
            ->where('skus.tenant_id', $company->tenant_id)
            ->where('skus.status', SkuStatus::Active->value)
            ->whereIn('skus.product_id', $this->scope->visibleProducts(Product::query(), $company)->select('products.id'));
    }
}
