<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

use App\Domain\Shared\Quantity;

/**
 * SKU vendável exibido ao comprador, com a regra de compra na unidade de
 * venda e o preço resolvido para a quantidade consultada.
 *
 * Sem preço vigente, $price é nulo e a tela exibe "Preço sob consulta".
 */
final readonly class CatalogSku
{
    public const string PRICE_ON_REQUEST = 'Preço sob consulta';

    /**
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public string $id,
        public string $code,
        public ?string $variant,
        public ?string $package,
        public string $unitCode,
        public string $unitName,
        public int $unitDecimals,
        public Quantity $minimum,
        public Quantity $multiple,
        public Quantity $quantity,
        public ?CatalogPrice $price,
        public array $attributes = [],
        public ?QuantityFeedback $feedback = null,
    ) {}

    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    public function priceLabel(): string
    {
        return $this->price?->unitPrice->format() ?? self::PRICE_ON_REQUEST;
    }

    public function priceSourceLabel(): ?string
    {
        return $this->price?->sourceLabel();
    }

    /**
     * Ex.: "Mínimo 10 CX · múltiplos de 5 CX".
     */
    public function purchaseRuleLabel(): string
    {
        $label = "Mínimo {$this->minimum->format()} {$this->unitCode}";

        if (! $this->multiple->equals(Quantity::of(1))) {
            $label .= " · múltiplos de {$this->multiple->format()} {$this->unitCode}";
        }

        return $label;
    }

    public function description(): string
    {
        return implode(' · ', array_values(array_filter(
            [$this->variant, $this->package],
            static fn (?string $part): bool => $part !== null && $part !== '',
        )));
    }
}
