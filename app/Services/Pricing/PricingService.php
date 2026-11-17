<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Domain\Pricing\Exceptions\PriceNotAvailable;
use App\Domain\Pricing\PriceCandidate;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PriceTier;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Enums\Pricing\PriceListScope;
use App\Enums\Pricing\PriceListStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Pricing\NegotiatedPrice;
use App\Models\Pricing\PriceListItem;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;

/**
 * Preço de venda para uma empresa compradora (servidor é a única fonte:
 * preços enviados pelo cliente nunca são aceitos).
 *
 * Carrega, em lote, condições negociadas e itens de tabelas aplicáveis
 * (padrão, grupo da empresa, empresa) vigentes na data e delega a
 * precedência ao PriceResolver do domínio. Resultados ficam em cache por
 * tenant, versão de preços, empresa, grupo, data, SKU e quantidade.
 */
final readonly class PricingService
{
    private const int CACHE_SECONDS = 600;

    public function __construct(
        private PriceResolver $resolver,
        private PriceVersion $version,
        private Cache $cache,
        private Clock $clock,
        private CurrentContext $context,
    ) {}

    /**
     * @param  array<string, Quantity>  $quantities  sku_id => quantidade na unidade de venda
     * @return array<string, ResolvedPrice|null> null quando não há preço vigente
     */
    public function resolve(BuyerCompany $buyer, array $quantities, ?CarbonImmutable $on = null): array
    {
        $date = ($on ?? $this->clock->today())->startOfDay();
        $version = $this->version->current();
        $results = [];
        $missing = [];

        foreach ($quantities as $skuId => $quantity) {
            $cached = $this->cache->get($this->key($buyer, $version, $date, $skuId, $quantity));

            if (is_array($cached)) {
                $results[$skuId] = $cached['available'] ? self::fromSnapshot($cached) : null;
            } else {
                $missing[$skuId] = $quantity;
            }
        }

        if ($missing === []) {
            return $results;
        }

        $candidates = $this->candidates($buyer, array_keys($missing), $date, $version);

        foreach ($missing as $skuId => $quantity) {
            try {
                $resolved = $this->resolver->resolve($candidates[$skuId] ?? [], $quantity, $date->toDateTimeImmutable());
                $snapshot = ['available' => true] + $resolved->snapshot();
            } catch (PriceNotAvailable) {
                $resolved = null;
                $snapshot = ['available' => false];
            }

            $this->cache->put($this->key($buyer, $version, $date, $skuId, $quantity), $snapshot, self::CACHE_SECONDS);
            $results[$skuId] = $resolved;
        }

        return $results;
    }

    /**
     * @param  list<string>  $skuIds
     * @return array<string, list<PriceCandidate>>
     */
    private function candidates(BuyerCompany $buyer, array $skuIds, CarbonImmutable $date, int $version): array
    {
        $day = $date->toDateString();
        $candidates = [];

        $negotiated = NegotiatedPrice::query()
            ->where('buyer_company_id', $buyer->id)
            ->whereIn('sku_id', $skuIds)
            ->where('status', NegotiatedPriceStatus::Active->value)
            ->where('valid_from', '<=', $day)
            ->where(static fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $day))
            ->get();

        foreach ($negotiated as $price) {
            $candidates[$price->sku_id][] = new PriceCandidate(
                source: PriceSource::Negotiated,
                referenceId: $price->id,
                unitPrice: $price->unit_price_cents,
                validFrom: $price->valid_from->toDateTimeImmutable(),
                validUntil: $price->valid_until?->toDateTimeImmutable(),
                version: $version,
            );
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
            ->get();

        foreach ($items as $item) {
            $list = $item->priceList;
            $candidates[$item->sku_id][] = new PriceCandidate(
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
                tiers: array_values($item->tiers->map(static fn ($t): PriceTier => new PriceTier($t->min_quantity, $t->unit_price_cents))->all()),
                version: $version,
            );
        }

        return $candidates;
    }

    private function key(BuyerCompany $buyer, int $version, CarbonImmutable $date, string $skuId, Quantity $quantity): string
    {
        return implode(':', [
            'pricing',
            $this->context->tenantId(),
            'v'.$version,
            $buyer->id,
            $buyer->customer_group_id ?? '-',
            $date->toDateString(),
            $skuId,
            $quantity->toDatabase(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private static function fromSnapshot(array $snapshot): ResolvedPrice
    {
        return new ResolvedPrice(
            unitPrice: Money::ofCents((int) $snapshot['unit_price_cents']),
            source: PriceSource::from((string) $snapshot['source']),
            referenceId: (string) $snapshot['reference_id'],
            tier: $snapshot['tier_min_quantity'] !== null
                ? new PriceTier(Quantity::of((string) $snapshot['tier_min_quantity']), Money::ofCents((int) $snapshot['unit_price_cents']))
                : null,
            minUnitPrice: null,
            version: isset($snapshot['version']) ? (int) $snapshot['version'] : null,
        );
    }
}
