<?php

declare(strict_types=1);

namespace App\Data\Ordering\Portal;

use App\Data\Ordering\CartLineQuote;
use App\Domain\Ordering\DocumentTotals;

/**
 * Carrinho aberto como exibido ao comprador: linhas precificadas no
 * servidor (CartPricing), problemas por linha e totais.
 *
 * overLimit indica que o total não pôde ser calculado por exceder o limite
 * suportado: as linhas aparecem sem preço e a tela pede redução das
 * quantidades.
 */
final readonly class CartView
{
    /**
     * @param  list<CartLineQuote>  $lines
     */
    public function __construct(
        public ?string $cartId,
        public array $lines,
        public ?DocumentTotals $totals,
        public bool $overLimit,
        public bool $companyCanPurchase,
        public string $companyStatusLabel,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * Apto à confirmação: há itens, todos sem problemas, e o total foi calculado.
     */
    public function isReady(): bool
    {
        if ($this->lines === [] || $this->totals === null || $this->overLimit) {
            return false;
        }

        foreach ($this->lines as $line) {
            if (! $line->isReady()) {
                return false;
            }
        }

        return true;
    }

    public function problemCount(): int
    {
        return count(array_filter($this->lines, static fn (CartLineQuote $line): bool => ! $line->isReady()));
    }
}
