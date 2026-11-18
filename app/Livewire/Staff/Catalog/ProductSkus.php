<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog;

use App\Actions\Catalog\SaveSku;
use App\Actions\Catalog\Support\CurrentSkuCosts;
use App\Data\Catalog\CommercialPolicyData;
use App\Data\Catalog\DecimalInput;
use App\Data\Catalog\SkuData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Catalog\PurchaseRule;
use App\Domain\Catalog\PurchaseRuleViolation;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Money;
use App\Domain\Shared\Percentage;
use App\Domain\Shared\Quantity;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\SkuStatus;
use App\Livewire\Staff\Catalog\Concerns\ChecksCatalogPermissions;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\SkuCommercialPolicy;
use App\Models\Catalog\UnitOfMeasure;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * SKUs de um produto: lista, formulário (embalagem, mínimo, múltiplo,
 * conversão) e simulação de quantidade pelas regras de compra do domínio.
 * Custo e política comercial só são carregados, exibidos e enviados com
 * catalog.view_costs.
 */
final class ProductSkus extends Component
{
    use ChecksCatalogPermissions;

    /** @var array<string, string> campo da Action => propriedade do formulário */
    private const array FIELDS = [
        'code' => 'code',
        'gtin' => 'gtin',
        'variant_label' => 'variantLabel',
        'attributes' => 'attributesText',
        'sales_unit_id' => 'salesUnitId',
        'conversion_factor' => 'conversionFactor',
        'package_label' => 'packageLabel',
        'min_order_quantity' => 'minOrderQuantity',
        'order_multiple' => 'orderMultiple',
        'weight_grams' => 'weightGrams',
        'cost' => 'cost',
        'min_unit_price' => 'minUnitPrice',
    ];

    #[Locked]
    public string $productId = '';

    #[Locked]
    public ?string $editingSkuId = null;

    public string $code = '';

    public string $gtin = '';

    public string $variantLabel = '';

    public string $attributesText = '';

    public string $salesUnitId = '';

    public string $conversionFactor = '1';

    public string $packageLabel = '';

    public string $minOrderQuantity = '1';

    public string $orderMultiple = '1';

    public string $weightGrams = '';

    public string $skuStatus = 'active';

    public string $cost = '';

    public string $minUnitPrice = '';

    public string $minMargin = '';

    public string $sampleQuantity = '';

    public ?string $message = null;

    public function mount(string $productId): void
    {
        $product = Product::query()->findOrFail(UntrustedId::uuid($productId, Product::class));
        $this->productId = $product->id;
        $this->salesUnitId = $product->base_unit_id;
    }

    public function edit(string $skuId): void
    {
        $sku = $this->sku($skuId);
        $this->resetErrorBag();
        $this->message = null;
        $this->editingSkuId = $sku->id;
        $this->code = $sku->code;
        $this->gtin = (string) $sku->gtin;
        $this->variantLabel = (string) $sku->variant_label;
        $this->attributesText = self::attributesToText($sku->attributes);
        $this->salesUnitId = $sku->sales_unit_id;
        $this->conversionFactor = DecimalInput::format($sku->conversion_factor);
        $this->packageLabel = (string) $sku->package_label;
        $this->minOrderQuantity = DecimalInput::format($sku->min_order_quantity->value);
        $this->orderMultiple = DecimalInput::format($sku->order_multiple->value);
        $this->weightGrams = $sku->weight_grams !== null ? (string) $sku->weight_grams : '';
        $this->skuStatus = $sku->status->value;
        $this->cost = '';
        $this->minUnitPrice = '';
        $this->minMargin = '';

        if ($this->canViewCosts()) {
            $current = CurrentSkuCosts::forSku($sku->id, app(Clock::class)->now());
            $policy = SkuCommercialPolicy::query()->where('sku_id', $sku->id)->first();
            $this->cost = $current !== null ? self::moneyInput($current->cost_cents) : '';
            $this->minUnitPrice = $policy?->min_unit_price_cents !== null ? self::moneyInput($policy->min_unit_price_cents) : '';
            $this->minMargin = $policy?->min_margin_bps !== null ? self::percentInput($policy->min_margin_bps) : '';
        }
    }

    public function cancel(): void
    {
        $this->reset('editingSkuId', 'code', 'gtin', 'variantLabel', 'attributesText', 'conversionFactor', 'packageLabel',
            'minOrderQuantity', 'orderMultiple', 'weightGrams', 'skuStatus', 'cost', 'minUnitPrice', 'minMargin', 'sampleQuantity');
        $this->salesUnitId = $this->product()->base_unit_id;
        $this->resetErrorBag();
    }

    public function save(SaveSku $save): void
    {
        $this->message = null;

        $this->validate([
            'code' => ['required', 'string', 'max:40'],
            'gtin' => ['nullable', 'string', 'max:20'],
            'variantLabel' => ['nullable', 'string', 'max:120'],
            'attributesText' => ['nullable', 'string', 'max:3000'],
            'salesUnitId' => ['required', 'uuid'],
            'conversionFactor' => ['required', 'string', 'max:25'],
            'packageLabel' => ['nullable', 'string', 'max:60'],
            'minOrderQuantity' => ['required', 'string', 'max:25'],
            'orderMultiple' => ['required', 'string', 'max:25'],
            'weightGrams' => ['nullable', 'integer', 'min:0', 'max:2000000000'],
            'skuStatus' => ['required', Rule::enum(SkuStatus::class)],
            'cost' => ['nullable', 'string', 'max:20'],
            'minUnitPrice' => ['nullable', 'string', 'max:20'],
            'minMargin' => ['nullable', 'string', 'max:8'],
        ], [], [
            'code' => 'código',
            'gtin' => 'GTIN',
            'variantLabel' => 'variação',
            'attributesText' => 'atributos',
            'salesUnitId' => 'unidade de venda',
            'conversionFactor' => 'fator de conversão',
            'packageLabel' => 'embalagem',
            'minOrderQuantity' => 'quantidade mínima',
            'orderMultiple' => 'múltiplo de venda',
            'weightGrams' => 'peso',
            'skuStatus' => 'situação',
            'cost' => 'custo',
            'minUnitPrice' => 'preço mínimo',
            'minMargin' => 'margem mínima',
        ]);

        $data = $this->formData();

        if ($data === null) {
            return;
        }

        $editing = $this->editingSkuId !== null;

        try {
            $save->handle($this->product(), $editing ? $this->sku($this->editingSkuId) : null, $data);
        } catch (InvalidCatalogData $e) {
            $this->addError(self::FIELDS[$e->fieldName()] ?? 'code', $e->getMessage());

            return;
        } catch (CatalogIdentifierInUse $e) {
            $this->addError('code', $e->getMessage());

            return;
        } catch (BusinessRuleViolation $e) {
            // Violações com campo associado (ex.: troca de embalagem com preço)
            // aparecem no campo; as demais, na situação do SKU.
            $field = $e->details()['field'] ?? null;
            $this->addError(is_string($field) && isset(self::FIELDS[$field]) ? self::FIELDS[$field] : 'skuStatus', $e->getMessage());

            return;
        }

        $this->cancel();
        $this->message = $editing ? 'SKU atualizado.' : 'SKU cadastrado.';
        $this->dispatch('catalog-product-changed');
    }

    public function render(): View
    {
        $product = Product::query()->with('baseUnit:id,code,decimals')->findOrFail($this->productId);
        $canViewCosts = $this->canViewCosts();
        $skus = Sku::query()
            ->with('salesUnit:id,code,decimals')
            ->where('product_id', $product->id)
            ->orderBy('code')
            ->get();
        $units = UnitOfMeasure::query()->orderBy('code')->get(['id', 'code', 'name', 'decimals']);

        $skuIds = array_values($skus->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());
        $costs = $canViewCosts ? CurrentSkuCosts::forSkus($skuIds, app(Clock::class)->now()) : [];
        $policies = $canViewCosts && $skuIds !== []
            ? SkuCommercialPolicy::query()->whereIn('sku_id', $skuIds)->get()->keyBy('sku_id')->all()
            : [];

        return view('livewire.staff.catalog.product-skus', [
            'product' => $product,
            'skus' => $skus,
            'units' => $units,
            'statuses' => SkuStatus::cases(),
            'costs' => $costs,
            'policies' => $policies,
            'canViewCosts' => $canViewCosts,
            'canEdit' => $this->canManageCatalog() && $product->status !== ProductStatus::Archived,
            'sample' => $this->sampleFeedback($units, $product->baseUnit),
        ]);
    }

    /**
     * Converte o formulário em dados da Action, registrando erros de formato.
     */
    private function formData(): ?SkuData
    {
        $factor = $this->parse('conversionFactor', 'Use números com vírgula decimal e até 6 casas (ex.: 12 ou 0,5).',
            fn (): BigDecimal => DecimalInput::decimal($this->conversionFactor, 6));
        $minimum = $this->parse('minOrderQuantity', 'Use números com vírgula decimal e até 3 casas (ex.: 24 ou 2,5).',
            fn (): Quantity => DecimalInput::quantity($this->minOrderQuantity));
        $multiple = $this->parse('orderMultiple', 'Use números com vírgula decimal e até 3 casas (ex.: 12 ou 0,5).',
            fn (): Quantity => DecimalInput::quantity($this->orderMultiple));
        $attributes = $this->parse('attributesText', 'Informe um atributo por linha no formato Nome: Valor (ex.: Cor: Azul), sem nomes repetidos.',
            fn (): array => self::attributesFromText($this->attributesText));

        $cost = null;
        $policy = null;

        if ($this->canViewCosts()) {
            $cost = $this->cost !== ''
                ? $this->parse('cost', 'Informe o custo em reais (ex.: 1.234,56).', fn (): Money => Money::parse($this->cost))
                : null;
            $minPrice = $this->minUnitPrice !== ''
                ? $this->parse('minUnitPrice', 'Informe o preço mínimo em reais (ex.: 19,90).', fn (): Money => Money::parse($this->minUnitPrice))
                : null;
            $minMargin = $this->minMargin !== ''
                ? $this->parse('minMargin', 'Informe a margem mínima em percentual, de 0 a 100 (ex.: 12,5).', fn (): Percentage => Percentage::parse($this->minMargin))
                : null;
            $policy = new CommercialPolicyData($minPrice, $minMargin);
        }

        // validate() limpa o error bag; qualquer erro aqui é falha de formato.
        if ($this->getErrorBag()->isNotEmpty() || $factor === null || $minimum === null || $multiple === null || $attributes === null) {
            return null;
        }

        return new SkuData(
            code: $this->code,
            salesUnitId: $this->salesUnitId,
            conversionFactor: $factor,
            minOrderQuantity: $minimum,
            orderMultiple: $multiple,
            gtin: $this->gtin,
            variantLabel: $this->variantLabel,
            attributes: $attributes,
            packageLabel: $this->packageLabel,
            weightGrams: $this->weightGrams !== '' ? (int) $this->weightGrams : null,
            status: SkuStatus::from($this->skuStatus),
            cost: $cost,
            commercialPolicy: $policy,
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $parser
     * @return T|null
     */
    private function parse(string $field, string $message, callable $parser): mixed
    {
        try {
            return $parser();
        } catch (AmountOutOfRange) {
            $this->addError($field, 'Valor acima do limite suportado.');

            return null;
        } catch (InvalidArgumentException) {
            $this->addError($field, $message);

            return null;
        }
    }

    /**
     * Simulação da regra de compra com os valores do formulário (sem gravar).
     *
     * @param  Collection<int, UnitOfMeasure>  $units
     * @return array{valid: bool, messages: list<string>, suggestion: string|null, base: string|null}|null
     */
    private function sampleFeedback(Collection $units, UnitOfMeasure $baseUnit): ?array
    {
        if (trim($this->sampleQuantity) === '') {
            return null;
        }

        $salesUnit = $units->firstWhere('id', $this->salesUnitId);

        if (! $salesUnit instanceof UnitOfMeasure) {
            return ['valid' => false, 'messages' => ['Escolha a unidade de venda para simular.'], 'suggestion' => null, 'base' => null];
        }

        try {
            $rule = new PurchaseRule(
                DecimalInput::quantity($this->minOrderQuantity),
                DecimalInput::quantity($this->orderMultiple),
                $salesUnit->decimals,
            );
        } catch (InvalidArgumentException|AmountOutOfRange) {
            return ['valid' => false, 'messages' => ['Preencha quantidade mínima e múltiplo válidos para simular.'], 'suggestion' => null, 'base' => null];
        }

        try {
            $quantity = DecimalInput::quantity($this->sampleQuantity);
        } catch (InvalidArgumentException|AmountOutOfRange) {
            return ['valid' => false, 'messages' => ['Quantidade de teste inválida. Use vírgula para decimais (ex.: 2,5).'], 'suggestion' => null, 'base' => null];
        }

        $package = trim($this->packageLabel);
        $messages = array_map(fn (PurchaseRuleViolation $violation): string => match ($violation) {
            PurchaseRuleViolation::NotPositive => 'Informe uma quantidade maior que zero.',
            PurchaseRuleViolation::TooManyDecimals => $salesUnit->decimals === 0
                ? "A unidade {$salesUnit->code} não aceita casas decimais."
                : "A unidade {$salesUnit->code} aceita até {$salesUnit->decimals} casas decimais.",
            PurchaseRuleViolation::BelowMinimum => "Abaixo da quantidade mínima de {$rule->minimum->format()} {$salesUnit->code}.",
            PurchaseRuleViolation::NotMultiple => "A quantidade deve ser múltipla de {$rule->multiple->format()} {$salesUnit->code}"
                .($package !== '' ? " ({$package})" : '').'.',
        }, $rule->violations($quantity));

        $base = null;

        try {
            $converted = $quantity->convertedBy(DecimalInput::decimal($this->conversionFactor, 6));
            $base = "{$quantity->format()} {$salesUnit->code} = {$converted->format()} {$baseUnit->code}";
        } catch (InvalidArgumentException|AmountOutOfRange) {
            $base = null;
        }

        $suggestion = null;

        if ($messages !== []) {
            try {
                $suggestion = $rule->nearestValid($quantity->isPositive() ? $quantity : $rule->minimum)->format().' '.$salesUnit->code;
            } catch (AmountOutOfRange) {
                $suggestion = null;
            }
        }

        return [
            'valid' => $messages === [],
            'messages' => array_values($messages),
            'suggestion' => $suggestion,
            'base' => $base,
        ];
    }

    private function product(): Product
    {
        return Product::query()->findOrFail($this->productId);
    }

    private function sku(string $skuId): Sku
    {
        return Sku::query()
            ->where('product_id', $this->productId)
            ->findOrFail(UntrustedId::uuid($skuId, Sku::class));
    }

    /**
     * @return array<string, string>
     */
    private static function attributesFromText(string $text): array
    {
        $attributes = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (! str_contains($line, ':')) {
                throw new InvalidArgumentException('Atributo sem separador.');
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));

            if ($name === '' || $value === '' || array_key_exists($name, $attributes)) {
                throw new InvalidArgumentException('Atributo incompleto ou repetido.');
            }

            $attributes[$name] = $value;
        }

        return $attributes;
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    private static function attributesToText(array $attributes): string
    {
        $lines = [];

        foreach ($attributes as $name => $value) {
            $lines[] = $name.': '.(is_scalar($value) ? (string) $value : '');
        }

        return implode("\n", $lines);
    }

    private static function moneyInput(Money $money): string
    {
        return str_replace('.', ',', $money->toDecimalString());
    }

    private static function percentInput(int $basisPoints): string
    {
        return rtrim(rtrim(str_replace('.', ',', (string) BigDecimal::ofUnscaledValue($basisPoints, 2)), '0'), ',');
    }
}
