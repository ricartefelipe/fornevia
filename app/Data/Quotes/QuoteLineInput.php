<?php

declare(strict_types=1);

namespace App\Data\Quotes;

use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;

/**
 * Linha da proposta informada pela equipe: SKU, quantidade na unidade de
 * venda e desconto. O preço de referência vem sempre do servidor (RS-030).
 */
final readonly class QuoteLineInput
{
    public function __construct(
        public string $skuId,
        public Quantity $quantity,
        public Percentage $discount,
    ) {}
}
