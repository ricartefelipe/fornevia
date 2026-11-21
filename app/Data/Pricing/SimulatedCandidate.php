<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PriceTier;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Candidato considerado na simulação, na ordem de precedência (ADR-011),
 * com a explicação de por que venceu ou perdeu para o vencedor.
 */
final readonly class SimulatedCandidate
{
    /**
     * @param  list<PriceTier>  $tiers
     */
    public function __construct(
        public int $position,
        public PriceSource $source,
        public string $referenceId,
        public string $description,
        public Money $baseUnitPrice,
        public Money $unitPrice,
        public ?PriceTier $appliedTier,
        public array $tiers,
        public int $priority,
        public CarbonImmutable $validFrom,
        public ?CarbonImmutable $validUntil,
        public bool $winner,
        public string $explanation,
    ) {}

    /**
     * Representação escalar para a interface (componentes Livewire).
     *
     * @return array{position: int, source: string, source_label: string, reference_id: string, description: string,
     *               base_unit_price: string, unit_price: string, applied_tier: string|null,
     *               tiers: list<array{min_quantity: string, unit_price: string}>, priority: int,
     *               valid_from: string, valid_until: string|null, winner: bool, explanation: string}
     */
    public function toArray(): array
    {
        return [
            'position' => $this->position,
            'source' => $this->source->value,
            'source_label' => $this->source->label(),
            'reference_id' => $this->referenceId,
            'description' => $this->description,
            'base_unit_price' => $this->baseUnitPrice->format(),
            'unit_price' => $this->unitPrice->format(),
            'applied_tier' => $this->appliedTier?->minQuantity->format(),
            'tiers' => array_map(static fn (PriceTier $tier): array => [
                'min_quantity' => $tier->minQuantity->format(),
                'unit_price' => $tier->unitPrice->format(),
            ], $this->tiers),
            'priority' => $this->priority,
            'valid_from' => $this->validFrom->format('d/m/Y'),
            'valid_until' => $this->validUntil?->format('d/m/Y'),
            'winner' => $this->winner,
            'explanation' => $this->explanation,
        ];
    }
}
