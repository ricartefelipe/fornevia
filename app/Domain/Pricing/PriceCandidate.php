<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use DateTimeImmutable;

/**
 * Preço possível para um SKU vindo de uma fonte (condição negociada ou
 * tabela), com vigência, prioridade e faixas.
 */
final readonly class PriceCandidate
{
    /**
     * @param  list<PriceTier>  $tiers
     */
    public function __construct(
        public PriceSource $source,
        public string $referenceId,
        public Money $unitPrice,
        public DateTimeImmutable $validFrom,
        public ?DateTimeImmutable $validUntil = null,
        public int $priority = 0,
        public array $tiers = [],
        public ?Money $minUnitPrice = null,
        public ?int $version = null,
    ) {}

    /**
     * Vigência inclusiva nas duas pontas, comparada por data (dia).
     */
    public function isValidOn(DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $this->validFrom->format('Y-m-d') <= $day
            && ($this->validUntil === null || $day <= $this->validUntil->format('Y-m-d'));
    }

    /**
     * Preço unitário para a quantidade: a maior faixa atingida, ou o preço base.
     *
     * @return array{0: Money, 1: PriceTier|null}
     */
    public function unitPriceFor(Quantity $quantity): array
    {
        $applied = null;

        foreach ($this->tiers as $tier) {
            if ($quantity->greaterThanOrEqual($tier->minQuantity)
                && ($applied === null || $tier->minQuantity->greaterThan($applied->minQuantity))) {
                $applied = $tier;
            }
        }

        return [$applied !== null ? $applied->unitPrice : $this->unitPrice, $applied];
    }
}
