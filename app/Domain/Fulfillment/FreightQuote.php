<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Shared\Money;

/**
 * Frete do distribuidor calculado para o pedido (RF-092), com a origem
 * para exibição. Não é cotação de transportadora.
 */
final readonly class FreightQuote
{
    public function __construct(
        public Money $amount,
        public string $source,
        public ?string $ruleId = null,
    ) {}

    /**
     * @return array{amount_cents: int, source: string, rule_id: string|null}
     */
    public function snapshot(): array
    {
        return ['amount_cents' => $this->amount->cents, 'source' => $this->source, 'rule_id' => $this->ruleId];
    }
}
