<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Domain\Pricing\PriceCandidate;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PriceTier;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Pricing\NegotiatedPrice;
use App\Models\Pricing\PriceListItem;
use App\Models\Pricing\PriceTier as PriceTierModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Candidatos de preço vigentes para uma empresa compradora: condições
 * negociadas ativas da empresa e itens de tabelas ativas aplicáveis
 * (padrão, grupo da empresa, empresa). Fonte única para o cálculo do
 * portal e dos documentos (PricingService) e para o simulador da equipe.
 */
final readonly class PriceCandidateLoader
{
    /**
     * @param  list<string>  $skuIds
     * @return array{candidates: array<string, list<PriceCandidate>>, descriptions: array<string, string>}
     */
    public function load(BuyerCompany $buyer, array $skuIds, CarbonImmutable $date, int $version): array
    {
        $day = $date->toDateString();
        $candidates = [];
        $descriptions = [];

        $negotiated = NegotiatedPrice::query()
            ->where('buyer_company_id', $buyer->id)
            ->whereIn('sku_id', $skuIds)
            ->where('status', NegotiatedPriceStatus::Active->value)
            ->where('valid_from', '<=', $day)
            ->where(static fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $day))
            ->orderBy('id')
            ->get();

        foreach ($negotiated as $price) {
            $candidate = new PriceCandidate(
                source: PriceSource::Negotiated,
                referenceId: $price->id,
                unitPrice: $price->unit_price_cents,
                validFrom: $price->valid_from->toDateTimeImmutable(),
                validUntil: $price->valid_until?->toDateTimeImmutable(),
                version: $version,
            );
            $candidates[$price->sku_id][] = $candidate;
            $descriptions[self::key($candidate)] = 'Condição negociada'.($price->notes !== null ? ' · '.$price->notes : '');
        }

        $items = PriceListItem::query()
            ->with(['priceList', 'tiers'])
            ->whereIn('sku_id', $skuIds)
            ->whereHas('priceList', static function (Builder $list) use ($buyer, $day): void {
                $list->where('status', PriceListStatus::Active->value)
                    ->where('valid_from', '<=', $day)
                    ->where(static fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $day))
                    ->where(static function (Builder $scope) use ($buyer): void {
                        $scope->where('scope', PriceListScope::Default->value)
                            ->orWhere(static fn (Builder $q) => $q->where('scope', PriceListScope::Customer->value)->where('buyer_company_id', $buyer->id));

                        if ($buyer->customer_group_id !== null) {
                            $scope->orWhere(static fn (Builder $q) => $q->where('scope', PriceListScope::Group->value)->where('customer_group_id', $buyer->customer_group_id));
                        }
                    });
            })
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $list = $item->priceList;
            $candidate = new PriceCandidate(
                source: match ($list->scope) {
                    PriceListScope::Customer => PriceSource::CustomerList,
                    PriceListScope::Group => PriceSource::GroupList,
                    PriceListScope::Default => PriceSource::DefaultList,
                },
                referenceId: $list->id,
                unitPrice: $item->unit_price_cents,
                validFrom: $list->valid_from->toDateTimeImmutable(),
                validUntil: $list->valid_until?->toDateTimeImmutable(),
                priority: $list->priority,
                tiers: array_values($item->tiers->map(static fn (PriceTierModel $t): PriceTier => new PriceTier($t->min_quantity, $t->unit_price_cents))->all()),
                version: $version,
            );
            $candidates[$item->sku_id][] = $candidate;
            $descriptions[self::key($candidate)] = 'Tabela '.$list->code.' · '.$list->name;
        }

        return ['candidates' => $candidates, 'descriptions' => $descriptions];
    }

    public static function key(PriceCandidate $candidate): string
    {
        return $candidate->source->value.'|'.$candidate->referenceId;
    }
}
