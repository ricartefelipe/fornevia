<?php

declare(strict_types=1);

namespace App\Data\Ordering;

use App\Domain\Ordering\Exceptions\CartNotReady;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;

/**
 * Linha de um pedido a gravar, com o retrato do item e os valores já
 * calculados no servidor (RN-034). Vem do carrinho ou de uma versão de
 * cotação aceita.
 */
final readonly class OrderLineDraft
{
    /**
     * @param  array<string, mixed>  $priceSource  origem do preço (retrato)
     */
    public function __construct(
        public string $skuId,
        public string $skuCode,
        public string $productName,
        public ?string $variantLabel,
        public string $unitCode,
        public ?string $packageLabel,
        public string $conversionFactor,
        public Quantity $quantity,
        public Quantity $baseQuantity,
        public Money $unitPrice,
        public int $discountBps,
        public Money $unitNetPrice,
        public Money $lineGross,
        public Money $lineTotal,
        public array $priceSource,
    ) {}

    public static function fromCartLine(CartLineQuote $line): self
    {
        $amounts = $line->amounts ?? throw new CartNotReady('Item sem preço.');

        return new self(
            skuId: $line->skuId,
            skuCode: $line->skuCode,
            productName: $line->productName,
            variantLabel: $line->variantLabel,
            unitCode: $line->unitCode,
            packageLabel: $line->packageLabel,
            conversionFactor: (string) ($line->conversionFactor ?? throw new CartNotReady('Conversão de unidade inválida.')),
            quantity: $line->quantity,
            baseQuantity: $line->baseQuantity ?? throw new CartNotReady('Conversão de unidade inválida.'),
            unitPrice: $amounts->unitPrice,
            discountBps: $amounts->discount->basisPoints,
            unitNetPrice: $amounts->unitNetPrice,
            lineGross: $amounts->gross,
            lineTotal: $amounts->total,
            priceSource: $line->price?->snapshot() ?? [],
        );
    }
}
