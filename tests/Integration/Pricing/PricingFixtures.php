<?php

declare(strict_types=1);

namespace Tests\Integration\Pricing;

use App\Actions\Pricing\ActivatePriceList;
use App\Actions\Pricing\SaveNegotiatedPrice;
use App\Actions\Pricing\SavePriceList;
use App\Actions\Pricing\SetPriceListItem;
use App\Data\Pricing\PriceListData;
use App\Domain\Pricing\ResolvedPrice;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Pricing\PriceListScope;
use App\Models\Audit\AuditLog;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Pricing\NegotiatedPrice;
use App\Models\Pricing\PriceList;
use App\Models\Pricing\PriceListItem;
use App\Services\Pricing\PriceVersion;
use App\Services\Pricing\PricingService;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\Builders\TenantScenario;

/**
 * Apoio aos testes de preços: catálogo mínimo (unidade, produto e SKU),
 * tabelas, itens e condições negociadas criados pelos caminhos reais da
 * aplicação (Actions), relógio controlado e leituras no contexto de sistema.
 */
final class PricingFixtures
{
    /**
     * Relógio fixo no instante informado (UTC), registrado no container.
     */
    public static function freezeClock(string $instant = '2026-11-17T15:00:00Z'): FrozenClock
    {
        $clock = FrozenClock::at($instant);
        app()->instance(Clock::class, $clock);

        return $clock;
    }

    public static function unit(TenantScenario $tenant, string $code = 'UN', int $decimals = 0): UnitOfMeasure
    {
        return $tenant->system(static function () use ($code, $decimals): UnitOfMeasure {
            $existing = UnitOfMeasure::query()->where('code', $code)->first();

            if ($existing !== null) {
                return $existing;
            }

            return UnitOfMeasure::query()->create([
                'code' => $code,
                'name' => match ($code) {
                    'UN' => 'Unidade',
                    'CX' => 'Caixa',
                    'KG' => 'Quilograma',
                    'L' => 'Litro',
                    default => 'Unidade '.$code,
                },
                'decimals' => $decimals,
            ]);
        });
    }

    public static function sku(
        TenantScenario $tenant,
        string $code,
        string $productName,
        string $unit = 'UN',
        int $decimals = 0,
        SkuStatus $status = SkuStatus::Active,
    ): Sku {
        $unitModel = self::unit($tenant, $unit, $decimals);

        return $tenant->system(static function () use ($code, $productName, $unitModel, $status): Sku {
            $product = new Product;
            $product->fill([
                'name' => $productName,
                'slug' => Str::slug($productName.'-'.$code),
                'brand' => 'Serra Azul',
            ]);
            $product->forceFill([
                'base_unit_id' => $unitModel->id,
                'status' => ProductStatus::Active,
                'visibility' => ProductVisibility::Public,
            ])->save();

            $sku = new Sku;
            $sku->fill(['code' => $code]);
            $sku->forceFill([
                'product_id' => $product->id,
                'sales_unit_id' => $unitModel->id,
                'status' => $status,
            ])->save();

            return Sku::query()->findOrFail($sku->id);
        });
    }

    public static function group(TenantScenario $tenant, string $code, string $name): CustomerGroup
    {
        return $tenant->system(static fn (): CustomerGroup => CustomerGroup::query()->create(['code' => $code, 'name' => $name]));
    }

    public static function company(TenantScenario $tenant, string $tradeName, ?CustomerGroup $group = null): BuyerCompany
    {
        return $tenant->addBuyerCompany([
            'trade_name' => $tradeName,
            'legal_name' => $tradeName.' Comércio Ltda',
            'customer_group_id' => $group?->id,
        ]);
    }

    public static function priceList(
        TenantScenario $tenant,
        string $code,
        PriceListScope $scope = PriceListScope::Default,
        ?string $targetId = null,
        int $priority = 0,
        string $from = '2026-01-01',
        ?string $until = null,
        bool $activate = true,
        ?Membership $actor = null,
    ): PriceList {
        $data = new PriceListData(
            code: $code,
            name: 'Tabela '.$code,
            scope: $scope,
            validFrom: CarbonImmutable::parse($from),
            validUntil: $until !== null ? CarbonImmutable::parse($until) : null,
            priority: $priority,
            customerGroupId: $scope === PriceListScope::Group ? $targetId : null,
            buyerCompanyId: $scope === PriceListScope::Customer ? $targetId : null,
        );

        return $tenant->actingAs($actor ?? $tenant->ownerMembership, static function () use ($data, $activate): PriceList {
            $list = app(SavePriceList::class)->handle(null, $data);

            return $activate ? app(ActivatePriceList::class)->handle($list) : $list;
        });
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tiers  [quantidade mínima, preço] em pt-BR
     */
    public static function item(TenantScenario $tenant, PriceList $list, Sku $sku, string $price, array $tiers = [], ?Membership $actor = null): PriceListItem
    {
        return $tenant->actingAs($actor ?? $tenant->ownerMembership, static fn (): PriceListItem => app(SetPriceListItem::class)->handle(
            $list,
            $sku,
            Money::parse($price),
            array_map(static fn (array $tier): array => [Quantity::of($tier[0]), Money::parse($tier[1])], $tiers),
        ));
    }

    public static function negotiated(
        TenantScenario $tenant,
        BuyerCompany $company,
        Sku $sku,
        string $price,
        string $from,
        ?string $until = null,
        ?Membership $actor = null,
        ?string $notes = null,
    ): NegotiatedPrice {
        return $tenant->actingAs($actor ?? $tenant->ownerMembership, static fn (): NegotiatedPrice => app(SaveNegotiatedPrice::class)->handle(
            $company,
            $sku,
            Money::parse($price),
            CarbonImmutable::parse($from),
            $until !== null ? CarbonImmutable::parse($until) : null,
            $notes,
        ));
    }

    /**
     * Preço resolvido pelo PricingService no contexto da associação informada.
     */
    public static function resolve(TenantScenario $tenant, Membership $as, BuyerCompany $buyer, Sku $sku, string $quantity, ?string $on = null): ?ResolvedPrice
    {
        return $tenant->actingAs($as, static fn (): ?ResolvedPrice => app(PricingService::class)->resolve(
            $buyer,
            [$sku->id => Quantity::of($quantity)],
            $on !== null ? CarbonImmutable::parse($on) : null,
        )[$sku->id] ?? null);
    }

    public static function version(TenantScenario $tenant): int
    {
        return $tenant->system(static fn (): int => app(PriceVersion::class)->current());
    }

    /**
     * Ações de auditoria registradas no tenant, em ordem cronológica.
     *
     * @return list<string>
     */
    public static function auditActions(TenantScenario $tenant, string $prefix = ''): array
    {
        return $tenant->system(static fn (): array => AuditLog::query()
            ->when($prefix !== '', static fn ($q) => $q->where('action', 'like', $prefix.'%'))
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('action')
            ->all());
    }

    /**
     * Executa um teste de componente Livewire como a associação informada
     * (Livewire::test não passa pelos middlewares de contexto).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asMember(TenantScenario $tenant, Membership $membership, Closure $callback): mixed
    {
        return $tenant->actingAs($membership, static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        });
    }
}
