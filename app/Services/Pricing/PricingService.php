<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Domain\Pricing\Exceptions\PriceNotAvailable;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PriceSource;
use App\Domain\Pricing\PriceTier;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\Buyers\BuyerCompany;
use App\Support\Clock\Clock;
use App\Support\Tenancy\ContextKind;
use App\Support\Tenancy\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use LogicException;

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
        private PriceCandidateLoader $loader,
    ) {}

    /**
     * @param  array<string, Quantity>  $quantities  sku_id => quantidade na unidade de venda
     * @return array<string, ResolvedPrice|null> null quando não há preço vigente
     */
    public function resolve(BuyerCompany $buyer, array $quantities, ?CarbonImmutable $on = null): array
    {
        $this->assertVisibleBuyer($buyer);

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

        $candidates = $this->loader->load($buyer, array_map('strval', array_keys($missing)), $date, $version)['candidates'];

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
     * O cache não passa pelo RLS: no contexto de comprador, só os preços da
     * própria empresa podem ser lidos ou gravados. Calcular para outra
     * empresa nesse contexto veria menos candidatos e envenenaria o cache.
     */
    private function assertVisibleBuyer(BuyerCompany $buyer): void
    {
        $active = $this->context->get();

        if ($active !== null && $active->kind === ContextKind::Buyer && $active->buyerCompanyId !== $buyer->id) {
            throw new LogicException('Preço de outra empresa solicitado no contexto de comprador.');
        }
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
