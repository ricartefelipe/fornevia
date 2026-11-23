<?php

declare(strict_types=1);

namespace App\Services\Ordering;

use App\Domain\Shared\Quantity;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Services\Catalog\BuyerCatalogScope;

/**
 * O que a empresa ainda pode comprar de um documento já gravado (pedido
 * aguardando aprovação, versão de cotação): empresa com permissão de
 * compra; itens visíveis para ela, vendáveis, dentro das regras de compra
 * (RN-020) e, quando o documento guardou a embalagem, com a mesma
 * embalagem. A visibilidade é calculada explicitamente para a empresa: as
 * verificações também rodam fora do contexto de comprador, onde o RLS não
 * filtra o catálogo.
 */
final readonly class PurchaseEligibility
{
    public function __construct(private BuyerCatalogScope $catalog) {}

    public function companyIssue(BuyerCompany $buyer): ?string
    {
        return $buyer->status->canPurchase()
            ? null
            : 'A empresa compradora está '.mb_strtolower($buyer->status->label()).' para compras.';
    }

    /**
     * @param  iterable<array{sku_id: string, sku_code: string, quantity: Quantity, packaging?: string|null}>  $lines
     */
    public function itemsIssue(BuyerCompany $buyer, iterable $lines): ?string
    {
        $lines = is_array($lines) ? $lines : iterator_to_array($lines, false);
        $skus = Sku::query()->with(['product', 'salesUnit'])->whereKey(array_column($lines, 'sku_id'))->get()->keyBy('id');
        $visible = $this->catalog->visibleProducts(Product::query(), $buyer)
            ->whereKey($skus->pluck('product_id')->all())
            ->pluck('products.id')
            ->mapWithKeys(static fn (mixed $id): array => [(string) $id => true])
            ->all();

        foreach ($lines as $line) {
            $sku = $skus->get($line['sku_id']);

            if ($sku === null || ! isset($visible[$sku->product_id]) || ! $sku->isSellable()) {
                return "O item {$line['sku_code']} não está mais disponível para a empresa.";
            }

            if ($sku->purchaseRule()->violations($line['quantity']) !== []) {
                return "A quantidade do item {$line['sku_code']} não atende mais às regras de compra (mínimo ou múltiplo).";
            }

            $packaging = $line['packaging'] ?? null;

            if ($packaging !== null && $packaging !== $sku->packagingFingerprint()) {
                return "A embalagem do item {$line['sku_code']} mudou desde a proposta.";
            }
        }

        return null;
    }
}
