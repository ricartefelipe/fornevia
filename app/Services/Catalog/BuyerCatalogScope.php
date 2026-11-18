<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Product;
use App\Support\Auth\Access;
use App\Support\Tenancy\CurrentContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * O que a empresa compradora do contexto atual enxerga do catálogo.
 *
 * A RLS já oculta do comprador produtos, SKUs e mídias não visíveis. O
 * mesmo critério é repetido aqui, de forma explícita na consulta (defesa em
 * profundidade), para que uma falha de política no banco não exponha itens:
 *
 * - produto ativo;
 * - visibilidade pública, ou produto restrito presente em catálogo privado
 *   ativo atribuído à empresa ou ao grupo de clientes dela;
 * - ao menos um SKU ativo (produto sem SKU vendável não aparece).
 */
final readonly class BuyerCatalogScope
{
    public function __construct(
        private Access $access,
        private CurrentContext $context,
    ) {}

    /**
     * Empresa compradora do contexto, após exigir a permissão de catálogo.
     *
     * @throws AuthorizationException
     */
    public function company(): BuyerCompany
    {
        $this->access->authorize(Permission::PortalCatalog);

        $companyId = $this->access->buyerCompanyId();

        if ($companyId === null || $this->context->require()->buyerCompanyId !== $companyId) {
            throw new AuthorizationException('O catálogo do portal exige o contexto da empresa compradora.');
        }

        return BuyerCompany::query()->whereKey($companyId)->firstOrFail();
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function visibleProducts(Builder $query, BuyerCompany $company): Builder
    {
        $tenantId = $company->tenant_id;

        return $query
            ->where('products.tenant_id', $tenantId)
            ->where('products.status', ProductStatus::Active->value)
            ->where(static function (Builder $visibility) use ($company, $tenantId): void {
                $visibility->where('products.visibility', ProductVisibility::Public->value)
                    ->orWhereExists(static function (QueryBuilder $assigned) use ($company, $tenantId): void {
                        $assigned->selectRaw('1')
                            ->from('private_catalog_products as pcp')
                            ->join('private_catalogs as pc', static function (JoinClause $join): void {
                                $join->on('pc.id', '=', 'pcp.private_catalog_id')
                                    ->on('pc.tenant_id', '=', 'pcp.tenant_id');
                            })
                            ->join('private_catalog_assignments as pca', static function (JoinClause $join): void {
                                $join->on('pca.private_catalog_id', '=', 'pc.id')
                                    ->on('pca.tenant_id', '=', 'pc.tenant_id');
                            })
                            ->whereColumn('pcp.product_id', 'products.id')
                            ->where('pcp.tenant_id', $tenantId)
                            ->where('pc.is_active', true)
                            ->where(static function (QueryBuilder $target) use ($company): void {
                                $target->where('pca.buyer_company_id', $company->id);

                                if ($company->customer_group_id !== null) {
                                    $target->orWhere('pca.customer_group_id', $company->customer_group_id);
                                }
                            });
                    });
            })
            ->whereExists(static function (QueryBuilder $sku) use ($tenantId): void {
                $sku->selectRaw('1')
                    ->from('skus as sellable')
                    ->whereColumn('sellable.product_id', 'products.id')
                    ->where('sellable.tenant_id', $tenantId)
                    ->where('sellable.status', SkuStatus::Active->value);
            });
    }
}
