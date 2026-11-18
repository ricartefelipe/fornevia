<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Catalog\Support\CatalogText;
use App\Actions\Catalog\Support\CurrentSkuCosts;
use App\Data\Catalog\CommercialPolicyData;
use App\Data\Catalog\SkuData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Catalog\Exceptions\ProductRequiresActiveSku;
use App\Domain\Catalog\Exceptions\SkuPackagingInUse;
use App\Domain\Catalog\PurchaseRule;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\SkuStatus;
use App\Enums\Identity\Permission;
use App\Enums\Pricing\NegotiatedPriceStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCommercialPolicy;
use App\Models\Catalog\SkuCost;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Pricing\NegotiatedPrice;
use App\Models\Pricing\PriceListItem;
use App\Services\Audit\AuditRecorder;
use App\Services\Pricing\PriceVersion;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\CurrentContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cadastra ou altera um SKU de um produto.
 *
 * Regras:
 * - código único no tenant; GTIN opcional com 8 a 14 dígitos;
 * - mínimo e múltiplo positivos, com as casas decimais da unidade de venda;
 * - fator de conversão > 0 com até 6 casas; mínimo e múltiplo convertidos
 *   para a unidade base precisam respeitar as casas da unidade base;
 * - produto ativo mantém ao menos um SKU ativo;
 * - custo e política comercial interna exigem catalog.view_costs: o custo
 *   gera nova linha em sku_costs somente quando muda; a política altera a
 *   versão de preços (preço mínimo participa da avaliação de preço).
 *
 * A auditoria registra quais dados internos mudaram, nunca os valores
 * (custos e margens só são visíveis com catalog.view_costs).
 *
 * - SKU com preço (item de tabela ou condição negociada ativa) não troca de
 *   unidade de venda nem de fator: os preços estão na unidade de venda.
 *
 * Ordem de locks: produto, SKU, unidade de venda e unidade base
 * (compartilhados). As Actions de preço leem o SKU com lock compartilhado,
 * e a redução de casas decimais trava a unidade para atualização.
 */
final readonly class SaveSku
{
    private const int MAX_ATTRIBUTES = 20;

    private const int MAX_WEIGHT_GRAMS = 2_000_000_000;

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private PriceVersion $prices,
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    public function handle(Product $product, ?Sku $sku, SkuData $data): Sku
    {
        $this->access->authorize(Permission::CatalogManage);

        if ($data->touchesRestrictedData()) {
            $this->access->authorize(Permission::CatalogViewCosts);
        }

        $code = self::code($data->code);
        $gtin = self::gtin($data->gtin);
        $variant = CatalogText::optional($data->variantLabel, 'variant_label', 120, 'A variação deve ter até 120 caracteres.');
        $package = CatalogText::optional($data->packageLabel, 'package_label', 60, 'O rótulo da embalagem deve ter até 60 caracteres.');
        $attributes = self::attributes($data->attributes);
        self::assertQuantities($data);
        self::assertRestricted($data);

        if ($data->weightGrams !== null && ($data->weightGrams < 0 || $data->weightGrams > self::MAX_WEIGHT_GRAMS)) {
            throw InvalidCatalogData::field('weight_grams', 'O peso deve ser um número de gramas entre 0 e 2.000.000.000.');
        }

        try {
            return DB::transaction(function () use ($product, $sku, $data, $code, $gtin, $variant, $package, $attributes): Sku {
                $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);

                if ($lockedProduct->status === ProductStatus::Archived) {
                    throw CatalogItemArchived::product();
                }

                if ($sku !== null) {
                    $sku = Sku::query()->where('product_id', $lockedProduct->id)->lockForUpdate()->findOrFail($sku->id);

                    if ($sku->status === SkuStatus::Archived) {
                        throw CatalogItemArchived::sku();
                    }
                } else {
                    $sku = new Sku;
                    $sku->forceFill(['product_id' => $lockedProduct->id]);
                }

                $creating = ! $sku->exists;
                $salesUnit = UnitOfMeasure::query()->sharedLock()->findOrFail(UntrustedId::uuid($data->salesUnitId, UnitOfMeasure::class));
                $baseUnit = UnitOfMeasure::query()->sharedLock()->findOrFail($lockedProduct->base_unit_id);

                self::assertUnitCompatibility($data, $salesUnit, $baseUnit);

                if (! $creating && self::packagingChanges($sku, $salesUnit, $data) && self::hasPrices($sku)) {
                    throw SkuPackagingInUse::priced();
                }

                if (Sku::query()->where('code', $code)->when(! $creating, static fn ($q) => $q->whereKeyNot($sku->id))->exists()) {
                    throw self::codeInUse($code);
                }

                if ($lockedProduct->status === ProductStatus::Active && $data->status !== SkuStatus::Active
                    && ($creating || $sku->status === SkuStatus::Active)
                    && ! Sku::query()->where('product_id', $lockedProduct->id)->where('status', SkuStatus::Active->value)
                        ->when(! $creating, static fn ($q) => $q->whereKeyNot($sku->id))->exists()) {
                    throw ProductRequiresActiveSku::toKeepActive();
                }

                $before = $creating ? null : self::snapshot($sku);
                $sku->forceFill([
                    'code' => $code,
                    'gtin' => $gtin,
                    'variant_label' => $variant,
                    // Objeto JSON mesmo quando vazio (restrição jsonb_typeof = 'object').
                    'attributes' => (object) $attributes,
                    'sales_unit_id' => $salesUnit->id,
                    'conversion_factor' => $data->conversionFactor,
                    'package_label' => $package,
                    'min_order_quantity' => $data->minOrderQuantity,
                    'order_multiple' => $data->orderMultiple,
                    'weight_grams' => $data->weightGrams,
                    'status' => $data->status,
                ]);

                // jsonb não preserva a ordem das chaves: a mesma lista em outra
                // ordem não é alteração.
                if (! $creating && $sku->getOriginal('attributes') == $attributes) {
                    $sku->syncOriginalAttribute('attributes');
                }

                $skuChanged = $creating || $sku->isDirty();

                if ($skuChanged) {
                    $sku->save();
                }

                $restricted = [];

                if ($data->cost !== null && $this->recordCost($sku, $data->cost)) {
                    $restricted[] = 'cost';
                }

                if ($data->commercialPolicy !== null && $this->savePolicy($sku, $data->commercialPolicy)) {
                    $restricted[] = 'commercial_policy';
                    $this->prices->bump();
                }

                if ($skuChanged || $restricted !== []) {
                    $this->audit->record($creating ? 'sku.created' : 'sku.updated', $sku, [
                        'product_id' => $lockedProduct->id,
                        'before' => $before,
                        'after' => self::snapshot($sku),
                        'restricted_changes' => $restricted,
                    ]);
                }

                return $sku;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::codeInUse($code);
        }
    }

    private static function packagingChanges(Sku $sku, UnitOfMeasure $salesUnit, SkuData $data): bool
    {
        return $sku->sales_unit_id !== $salesUnit->id
            || ! $sku->conversion_factor->isEqualTo($data->conversionFactor);
    }

    private static function hasPrices(Sku $sku): bool
    {
        return PriceListItem::query()->where('sku_id', $sku->id)->exists()
            || NegotiatedPrice::query()->where('sku_id', $sku->id)->where('status', NegotiatedPriceStatus::Active->value)->exists();
    }

    private function recordCost(Sku $sku, Money $cost): bool
    {
        $current = CurrentSkuCosts::forSku($sku->id, $this->clock->now());

        if ($current !== null && $current->cost_cents->equals($cost)) {
            return false;
        }

        $entry = new SkuCost;
        // sku_costs só tem created_at (preenchido pelo banco).
        $entry->timestamps = false;
        $entry->forceFill([
            'sku_id' => $sku->id,
            'cost_cents' => $cost,
            'effective_from' => $this->clock->now(),
            'recorded_by_user_id' => $this->context->require()->userId,
        ])->save();

        return true;
    }

    private function savePolicy(Sku $sku, CommercialPolicyData $policy): bool
    {
        $existing = SkuCommercialPolicy::query()->where('sku_id', $sku->id)->lockForUpdate()->first();

        if ($policy->isEmpty()) {
            $existing?->delete();

            return $existing !== null;
        }

        $record = $existing ?? new SkuCommercialPolicy;
        $record->forceFill([
            'sku_id' => $sku->id,
            'min_unit_price_cents' => $policy->minUnitPrice,
            'min_margin_bps' => $policy->minMargin?->basisPoints,
        ]);

        if ($record->exists && ! $record->isDirty()) {
            return false;
        }

        $record->save();

        return true;
    }

    private static function code(string $value): string
    {
        $code = mb_strtoupper(trim($value));

        if (preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/D', $code) !== 1) {
            throw InvalidCatalogData::field('code', 'O código do SKU deve ter até 40 caracteres: letras, números, ponto, hífen, barra ou sublinhado.');
        }

        return $code;
    }

    private static function gtin(?string $value): ?string
    {
        $gtin = $value === null ? '' : str_replace(' ', '', trim($value));

        if ($gtin === '') {
            return null;
        }

        if (preg_match('/^\d{8,14}$/D', $gtin) !== 1) {
            throw InvalidCatalogData::field('gtin', 'O GTIN deve ter de 8 a 14 dígitos.');
        }

        return $gtin;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, string>
     */
    private static function attributes(array $attributes): array
    {
        if (count($attributes) > self::MAX_ATTRIBUTES) {
            throw InvalidCatalogData::field('attributes', 'Informe no máximo '.self::MAX_ATTRIBUTES.' atributos.');
        }

        $normalized = [];

        foreach ($attributes as $key => $value) {
            $name = CatalogText::optional((string) $key, 'attributes', 40, 'O nome de cada atributo deve ter até 40 caracteres.');
            $text = is_string($value) ? CatalogText::optional($value, 'attributes', 120, 'O valor de cada atributo deve ter até 120 caracteres.') : null;

            if ($name === null || $text === null) {
                throw InvalidCatalogData::field('attributes', 'Cada atributo precisa de nome e valor (ex.: Cor: Azul).');
            }

            if (array_key_exists($name, $normalized)) {
                throw InvalidCatalogData::field('attributes', "O atributo {$name} foi informado mais de uma vez.");
            }

            $normalized[$name] = $text;
        }

        return $normalized;
    }

    private static function assertQuantities(SkuData $data): void
    {
        $factor = $data->conversionFactor;

        if (! $factor->isPositive()) {
            throw InvalidCatalogData::field('conversion_factor', 'O fator de conversão deve ser maior que zero.');
        }

        if ($factor->strippedOfTrailingZeros()->getScale() > 6 || $factor->isGreaterThanOrEqualTo(BigDecimal::of('1000000000000'))) {
            throw InvalidCatalogData::field('conversion_factor', 'O fator de conversão aceita até 12 dígitos inteiros e 6 casas decimais.');
        }

        $limit = Quantity::of(PurchaseRule::MAX_QUANTITY);

        foreach (['min_order_quantity' => $data->minOrderQuantity, 'order_multiple' => $data->orderMultiple] as $field => $quantity) {
            if (! $quantity->isPositive()) {
                throw InvalidCatalogData::field($field, $field === 'min_order_quantity'
                    ? 'A quantidade mínima deve ser maior que zero.'
                    : 'O múltiplo de venda deve ser maior que zero.');
            }

            if ($quantity->greaterThan($limit)) {
                throw InvalidCatalogData::field($field, 'A quantidade deve ser de no máximo '.$limit->format().'.');
            }
        }
    }

    private static function assertRestricted(SkuData $data): void
    {
        if ($data->cost !== null && $data->cost->isNegative()) {
            throw InvalidCatalogData::field('cost', 'O custo não pode ser negativo.');
        }

        if ($data->commercialPolicy?->minUnitPrice?->isNegative() === true) {
            throw InvalidCatalogData::field('min_unit_price', 'O preço mínimo não pode ser negativo.');
        }
    }

    /**
     * Mínimo e múltiplo respeitam as casas da unidade de venda, e suas
     * conversões para a unidade base (quantidade × fator) são exatas nas
     * casas da unidade base, que é a unidade do estoque.
     */
    private static function assertUnitCompatibility(SkuData $data, UnitOfMeasure $salesUnit, UnitOfMeasure $baseUnit): void
    {
        foreach (['min_order_quantity' => $data->minOrderQuantity, 'order_multiple' => $data->orderMultiple] as $field => $quantity) {
            if ($quantity->decimalPlaces() > $salesUnit->decimals) {
                throw InvalidCatalogData::field($field, $salesUnit->decimals === 0
                    ? "A unidade {$salesUnit->code} não aceita casas decimais."
                    : "A unidade {$salesUnit->code} aceita até {$salesUnit->decimals} casas decimais.");
            }

            try {
                $base = $quantity->convertedBy($data->conversionFactor);
            } catch (InvalidArgumentException) {
                $base = null;
            } catch (AmountOutOfRange) {
                throw InvalidCatalogData::field('conversion_factor', "Com este fator, {$quantity->format()} {$salesUnit->code} excede o limite de quantidade em {$baseUnit->code}.");
            }

            if ($base === null || $base->decimalPlaces() > $baseUnit->decimals) {
                throw InvalidCatalogData::field('conversion_factor', "Com este fator, {$quantity->format()} {$salesUnit->code} não corresponde a uma quantidade exata em {$baseUnit->code}.");
            }
        }
    }

    private static function codeInUse(string $code): CatalogIdentifierInUse
    {
        return CatalogIdentifierInUse::for('code', $code, "Já existe um SKU com o código {$code}.");
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Sku $sku): array
    {
        return [
            'code' => $sku->code,
            'gtin' => $sku->gtin,
            'variant_label' => $sku->variant_label,
            'attributes' => $sku->attributes,
            'sales_unit_id' => $sku->sales_unit_id,
            'conversion_factor' => (string) $sku->conversion_factor->toScale(6),
            'package_label' => $sku->package_label,
            'min_order_quantity' => $sku->min_order_quantity->toDatabase(),
            'order_multiple' => $sku->order_multiple->toDatabase(),
            'weight_grams' => $sku->weight_grams,
            'status' => $sku->status->value,
        ];
    }
}
