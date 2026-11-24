<?php

declare(strict_types=1);

namespace App\Domain\Fulfillment;

use App\Domain\Shared\Money;

/**
 * Regra de frete do distribuidor: valor fixo por pedido para as UFs
 * atendidas (nenhuma = todas), com isenção opcional a partir de um subtotal.
 */
final readonly class FreightRuleView
{
    /**
     * @param  list<string>  $states
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $states,
        public Money $amount,
        public ?Money $freeFrom,
        public int $priority,
    ) {}

    public function serves(?string $state): bool
    {
        return $this->states === [] || ($state !== null && in_array(mb_strtoupper($state), $this->states, true));
    }
}
