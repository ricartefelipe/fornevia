<?php

declare(strict_types=1);

namespace App\Data\Pricing;

use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use Carbon\CarbonImmutable;

/**
 * Resultado da simulação de preço: o preço resolvido pelo PricingService
 * (mesmo caminho do portal e dos documentos comerciais) e todos os
 * candidatos considerados, ordenados pela precedência.
 */
final readonly class PriceSimulation
{
    /**
     * @param  list<SimulatedCandidate>  $candidates
     */
    public function __construct(
        public string $buyerCompanyId,
        public string $buyerCompanyName,
        public string $skuId,
        public string $skuDescription,
        public Quantity $quantity,
        public CarbonImmutable $date,
        public ?ResolvedPrice $resolved,
        public ?Money $lineTotal,
        public array $candidates,
        public int $priceVersion,
    ) {}

    public function winner(): ?SimulatedCandidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->winner) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Representação escalar para a interface (componentes Livewire).
     *
     * @return array{buyer_company: string, sku: string, quantity: string, date: string, available: bool,
     *               unit_price: string|null, source_label: string|null, tier: string|null, line_total: string|null,
     *               price_version: int, candidates: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'buyer_company' => $this->buyerCompanyName,
            'sku' => $this->skuDescription,
            'quantity' => $this->quantity->format(),
            'date' => $this->date->format('d/m/Y'),
            'available' => $this->resolved !== null,
            'unit_price' => $this->resolved?->unitPrice->format(),
            'source_label' => $this->resolved?->source->label(),
            'tier' => $this->resolved?->tier?->minQuantity->format(),
            'line_total' => $this->lineTotal?->format(),
            'price_version' => $this->priceVersion,
            'candidates' => array_map(static fn (SimulatedCandidate $c): array => $c->toArray(), $this->candidates),
        ];
    }
}
