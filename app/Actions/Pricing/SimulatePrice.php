<?php

declare(strict_types=1);

namespace App\Actions\Pricing;

use App\Data\Pricing\PriceSimulation;
use App\Data\Pricing\SimulatedCandidate;
use App\Domain\Pricing\Exceptions\InvalidQuantity;
use App\Domain\Pricing\PriceCandidate;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceTier as Tier;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Buyers\BuyerCompany;
use App\Models\Catalog\Sku;
use App\Services\Pricing\PriceCandidateLoader;
use App\Services\Pricing\PriceVersion;
use App\Services\Pricing\PricingService;
use App\Support\Auth\Access;
use Carbon\CarbonImmutable;

/**
 * Simulador de preço da equipe: mostra o preço resolvido pelo
 * PricingService (o mesmo caminho do portal e dos documentos) e explica a
 * precedência (ADR-011) listando todos os candidatos vigentes na data.
 *
 * A ordem dos candidatos é obtida aplicando o próprio PriceResolver de forma
 * sucessiva (vencedor, depois o vencedor entre os restantes, e assim por
 * diante); a regra de desempate não é reimplementada aqui.
 */
final readonly class SimulatePrice
{
    public function __construct(
        private Access $access,
        private PricingService $pricing,
        private PriceResolver $resolver,
        private PriceVersion $version,
        private PriceCandidateLoader $loader,
    ) {}

    public function handle(BuyerCompany $buyer, Sku $sku, Quantity $quantity, CarbonImmutable $on): PriceSimulation
    {
        $this->access->authorize(Permission::PricingView);

        if (! $quantity->isPositive()) {
            throw new InvalidQuantity('A quantidade deve ser maior que zero.', ['field' => 'quantity']);
        }

        $buyer = BuyerCompany::query()->findOrFail($buyer->id);
        $sku = Sku::query()->with('product')->findOrFail($sku->id);
        $date = CarbonImmutable::parse($on->toDateString());

        $resolved = $this->pricing->resolve($buyer, [$sku->id => $quantity], $date)[$sku->id] ?? null;
        $version = $this->version->current();

        $loaded = $this->loader->load($buyer, [$sku->id], $date, $version);
        $candidates = $loaded['candidates'][$sku->id] ?? [];
        $descriptions = $loaded['descriptions'];
        $ordered = $this->rank($candidates, $quantity, $date);
        $simulated = [];

        foreach ($ordered as $index => $candidate) {
            [$unitPrice, $tier] = $candidate->unitPriceFor($quantity);
            $isWinner = $resolved !== null
                && $candidate->source === $resolved->source
                && $candidate->referenceId === $resolved->referenceId;

            $simulated[] = new SimulatedCandidate(
                position: $index + 1,
                source: $candidate->source,
                referenceId: $candidate->referenceId,
                description: $descriptions[self::key($candidate)] ?? $candidate->source->label(),
                baseUnitPrice: $candidate->unitPrice,
                unitPrice: $unitPrice,
                appliedTier: $tier,
                tiers: $candidate->tiers,
                priority: $candidate->priority,
                validFrom: CarbonImmutable::instance($candidate->validFrom),
                validUntil: $candidate->validUntil !== null ? CarbonImmutable::instance($candidate->validUntil) : null,
                winner: $isWinner,
                explanation: $index === 0
                    ? self::winnerExplanation($candidate, $tier, count($ordered))
                    : self::loserExplanation($candidate, $ordered[0]),
            );
        }

        return new PriceSimulation(
            buyerCompanyId: $buyer->id,
            buyerCompanyName: $buyer->trade_name,
            skuId: $sku->id,
            skuDescription: $sku->code.' · '.$sku->product->name.($sku->variant_label !== null ? ' ('.$sku->variant_label.')' : ''),
            quantity: $quantity,
            date: $date,
            resolved: $resolved,
            lineTotal: $resolved?->unitPrice->times($quantity),
            candidates: $simulated,
            priceVersion: $version,
        );
    }

    /**
     * @param  list<PriceCandidate>  $candidates
     * @return list<PriceCandidate>
     */
    private function rank(array $candidates, Quantity $quantity, CarbonImmutable $date): array
    {
        $remaining = [];

        foreach ($candidates as $candidate) {
            $remaining[self::key($candidate)] = $candidate;
        }

        $ordered = [];

        while ($remaining !== []) {
            $resolved = $this->resolver->resolve(array_values($remaining), $quantity, $date->toDateTimeImmutable());
            $key = $resolved->source->value.'|'.$resolved->referenceId;
            $ordered[] = $remaining[$key];
            unset($remaining[$key]);
        }

        return $ordered;
    }

    private static function key(PriceCandidate $candidate): string
    {
        return PriceCandidateLoader::key($candidate);
    }

    private static function winnerExplanation(PriceCandidate $candidate, ?Tier $tier, int $total): string
    {
        $base = $total === 1
            ? 'Única fonte de preço vigente para a empresa, o SKU e a data.'
            : "Maior precedência entre {$total} candidatos (origem, prioridade, início de vigência e criação).";

        $tierText = $tier !== null
            ? ' Faixa a partir de '.$tier->minQuantity->format().' aplicada a todas as unidades.'
            : ($candidate->tiers !== [] ? ' Quantidade abaixo da primeira faixa: vale o preço base.' : '');

        return $base.$tierText;
    }

    private static function loserExplanation(PriceCandidate $candidate, PriceCandidate $winner): string
    {
        if ($candidate->source !== $winner->source) {
            return "{$winner->source->label()} tem precedência sobre {$candidate->source->label()}.";
        }

        if ($candidate->priority !== $winner->priority) {
            return "Mesma origem; prioridade {$candidate->priority} menor que {$winner->priority}.";
        }

        if ($candidate->validFrom->format('Y-m-d') !== $winner->validFrom->format('Y-m-d')) {
            return 'Mesma origem e prioridade; início de vigência em '.$candidate->validFrom->format('d/m/Y')
                .' anterior ao da vencedora ('.$winner->validFrom->format('d/m/Y').').';
        }

        return 'Empate em origem, prioridade e início de vigência; prevalece a tabela cadastrada primeiro.';
    }
}
