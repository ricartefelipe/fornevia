<?php

declare(strict_types=1);

namespace Tests\Integration\Catalog\Staff;

use App\Data\Catalog\CommercialPolicyData;
use App\Data\Catalog\SkuData;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\MembershipKind;
use App\Enums\Identity\MembershipStatus;
use App\Enums\Identity\Permission;
use App\Models\Audit\AuditLog;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Identity\Membership;
use App\Models\Identity\Role;
use App\Models\Identity\User;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\TenantScenario;

/**
 * Apoio aos testes do catálogo da equipe: cadastros mínimos gravados no
 * contexto de sistema do tenant (sem passar pelas Actions em teste),
 * papéis personalizados e leituras de auditoria.
 */
final class CatalogFixtures
{
    public static function freezeClock(string $instant = '2026-11-17 13:00:00'): FrozenClock
    {
        $clock = FrozenClock::at($instant);
        app()->instance(Clock::class, $clock);

        return $clock;
    }

    /**
     * Unidade pelo código. O onboarding já cria UN, PC, CX, PCT, PAR, RL,
     * M (2 casas), KG e L (3 casas); demais códigos são cadastrados aqui.
     */
    public static function unit(TenantScenario $tenant, string $code = 'UN', int $decimals = 0, ?string $name = null): UnitOfMeasure
    {
        return $tenant->system(static function () use ($code, $decimals, $name): UnitOfMeasure {
            $existing = UnitOfMeasure::query()->where('code', $code)->first();

            if ($existing !== null) {
                return $existing;
            }

            $unit = new UnitOfMeasure;
            $unit->forceFill(['code' => $code, 'name' => $name ?? "Unidade {$code}", 'decimals' => $decimals])->save();

            return $unit;
        });
    }

    public static function category(TenantScenario $tenant, string $name, ?Category $parent = null): Category
    {
        return $tenant->system(static function () use ($name, $parent): Category {
            $category = new Category;
            $category->forceFill([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
                'parent_id' => $parent?->id,
            ])->save();

            return $category;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function product(TenantScenario $tenant, UnitOfMeasure $baseUnit, array $attributes = []): Product
    {
        return $tenant->system(static function () use ($baseUnit, $attributes): Product {
            $name = (string) ($attributes['name'] ?? 'Café torrado em grãos 1 kg');
            $product = new Product;
            $product->forceFill([
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'base_unit_id' => $baseUnit->id,
                'status' => ProductStatus::Draft,
                'visibility' => ProductVisibility::Public,
                ...$attributes,
            ])->save();

            return $product;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function sku(TenantScenario $tenant, Product $product, UnitOfMeasure $salesUnit, string $code, array $attributes = []): Sku
    {
        return $tenant->system(static function () use ($product, $salesUnit, $code, $attributes): Sku {
            $sku = new Sku;
            $sku->forceFill([
                'product_id' => $product->id,
                'sales_unit_id' => $salesUnit->id,
                'code' => $code,
                'conversion_factor' => BigDecimal::one(),
                'min_order_quantity' => Quantity::of(1),
                'order_multiple' => Quantity::of(1),
                'status' => SkuStatus::Active,
                ...$attributes,
            ])->save();

            return $sku;
        });
    }

    /**
     * Associação de equipe com papel personalizado (permissões exatas).
     *
     * @param  list<Permission>  $permissions
     */
    public static function staffWith(TenantScenario $tenant, array $permissions, ?User $user = null): Membership
    {
        $user ??= User::factory()->create();

        return $tenant->system(static function () use ($permissions, $user): Membership {
            $role = new Role;
            $role->forceFill([
                'key' => 'personalizado_'.Str::lower(Str::random(8)),
                'name' => 'Cadastro de catálogo',
                'kind' => MembershipKind::Staff,
                'permissions' => array_map(static fn (Permission $p): string => $p->value, $permissions),
                'max_discount_bps' => 0,
                'is_system' => false,
            ])->save();

            $membership = new Membership;
            $membership->forceFill([
                'user_id' => $user->id,
                'kind' => MembershipKind::Staff,
                'role_id' => $role->id,
                'status' => MembershipStatus::Active,
            ])->save();

            return $membership;
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function skuData(UnitOfMeasure $salesUnit, string $code = 'CAF-1KG', array $overrides = []): SkuData
    {
        $defaults = [
            'code' => $code,
            'salesUnitId' => $salesUnit->id,
            'conversionFactor' => BigDecimal::one(),
            'minOrderQuantity' => Quantity::of(1),
            'orderMultiple' => Quantity::of(1),
            'gtin' => null,
            'variantLabel' => null,
            'attributes' => [],
            'packageLabel' => null,
            'weightGrams' => null,
            'status' => SkuStatus::Active,
            'cost' => null,
            'commercialPolicy' => null,
        ];

        /** @var array{code: string, salesUnitId: string, conversionFactor: BigDecimal, minOrderQuantity: Quantity, orderMultiple: Quantity, gtin: string|null, variantLabel: string|null, attributes: array<string, string>, packageLabel: string|null, weightGrams: int|null, status: SkuStatus, cost: Money|null, commercialPolicy: CommercialPolicyData|null} $values */
        $values = [...$defaults, ...$overrides];

        return new SkuData(...$values);
    }

    /**
     * @return Collection<int, AuditLog>
     */
    public static function audits(TenantScenario $tenant, string $action): Collection
    {
        return $tenant->system(static fn (): Collection => AuditLog::query()->where('action', $action)->orderBy('created_at')->get());
    }

    public static function priceVersion(TenantScenario $tenant): int
    {
        return $tenant->system(static function () use ($tenant): int {
            $version = DB::table('pricing_settings')->where('tenant_id', $tenant->organization->id)->value('price_version');

            return is_numeric($version) ? (int) $version : 1;
        });
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay();
    }
}
