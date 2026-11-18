<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

use App\Domain\Catalog\PurchaseRuleViolation;
use App\Domain\Shared\Quantity;

/**
 * Resultado da conferência de uma quantidade digitada pelo comprador
 * contra a regra de compra do SKU (mínimo, múltiplo e casas decimais).
 */
final readonly class QuantityFeedback
{
    /**
     * @param  list<PurchaseRuleViolation>  $violations
     * @param  list<string>  $messages
     */
    public function __construct(
        public string $input,
        public ?Quantity $requested,
        public bool $valid,
        public array $violations,
        public array $messages,
        public ?Quantity $suggestion,
    ) {}

    public function hasSuggestion(): bool
    {
        return ! $this->valid && $this->suggestion !== null;
    }
}
