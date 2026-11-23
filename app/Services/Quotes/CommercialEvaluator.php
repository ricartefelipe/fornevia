<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Actions\Catalog\Support\CurrentSkuCosts;
use App\Data\Quotes\PricedQuoteLine;
use App\Domain\Pricing\DiscountPolicy;
use App\Domain\Quotes\CommercialReview;
use App\Domain\Quotes\CommercialReviewLine;
use App\Domain\Shared\Percentage;
use App\Models\Catalog\SkuCommercialPolicy;
use App\Models\Pricing\PricingPolicy;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;

/**
 * Avaliação comercial da proposta (RN-032, RN-033): para cada linha, a
 * política de desconto com o limite do papel do autor, o preço mínimo e a
 * margem mínima do SKU (ou a padrão do distribuidor) e o custo vigente. Sem
 * custo cadastrado, margem e custo não são avaliados. Dados internos
 * (ADR-020): só contexto de equipe ou sistema.
 */
final readonly class CommercialEvaluator
{
    public function __construct(
        private DiscountPolicy $policy,
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    /**
     * @param  list<PricedQuoteLine>  $lines
     */
    public function evaluate(array $lines, Percentage $authorLimit): CommercialReview
    {
        $skuIds = array_map(static fn (PricedQuoteLine $line): string => $line->sku->id, $lines);
        $policies = SkuCommercialPolicy::query()->whereIn('sku_id', $skuIds)->get()->keyBy('sku_id');
        $costs = CurrentSkuCosts::forSkus($skuIds, $this->clock->now());
        $defaultMargin = (int) (PricingPolicy::query()->whereKey($this->context->tenantId())->value('min_margin_bps') ?? 0);
        $reviewed = [];

        foreach ($lines as $line) {
            $skuPolicy = $policies->get($line->sku->id);
            $minPrice = $skuPolicy?->min_unit_price_cents;
            $minMargin = Percentage::ofBasisPoints($skuPolicy->min_margin_bps ?? $defaultMargin, allowOutOfRange: true);
            $cost = isset($costs[$line->sku->id]) ? $costs[$line->sku->id]->cost_cents : null;

            $reviewed[] = new CommercialReviewLine(
                $line->lineNumber,
                $line->sku->id,
                $this->policy->evaluate($line->amounts->unitPrice, $line->amounts->discount, $authorLimit, $minPrice, $cost, $minMargin),
                $cost,
                $minPrice,
                $minMargin,
            );
        }

        return new CommercialReview($authorLimit, $reviewed);
    }
}
