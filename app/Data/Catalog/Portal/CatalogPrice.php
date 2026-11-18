<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;

/**
 * Preço exibido ao comprador para um SKU e uma quantidade.
 *
 * Contém apenas o que o comprador pode ver: preço unitário, origem e total
 * da quantidade consultada. Referências internas (tabela vencedora, preço
 * mínimo, custo ou margem) não fazem parte deste objeto.
 */
final readonly class CatalogPrice
{
    public function __construct(
        public Money $unitPrice,
        public PriceSource $source,
        public Quantity $quantity,
        public ?Money $total,
        public ?Quantity $tierMinQuantity,
    ) {}

    /**
     * Total = preço unitário (já arredondado ao centavo) × quantidade,
     * arredondado HALF_UP ao centavo (ADR-004). Null quando o total excede o
     * limite de valores monetários (quantidade incompatível com o preço).
     */
    public static function fromResolved(ResolvedPrice $resolved, Quantity $quantity): self
    {
        try {
            $total = $resolved->unitPrice->times($quantity);
        } catch (AmountOutOfRange) {
            $total = null;
        }

        return new self(
            unitPrice: $resolved->unitPrice,
            source: $resolved->source,
            quantity: $quantity,
            total: $total,
            tierMinQuantity: $resolved->tier?->minQuantity,
        );
    }

    public function sourceLabel(): string
    {
        return $this->source->label();
    }

    /**
     * Faixa por volume atingida: o preço vale para todas as unidades (ADR-010).
     */
    public function hasVolumeTier(): bool
    {
        return $this->tierMinQuantity !== null;
    }
}
