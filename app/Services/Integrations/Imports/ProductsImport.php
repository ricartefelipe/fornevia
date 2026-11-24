<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SaveSku;
use App\Actions\Pricing\SetPriceListItem;
use App\Data\Catalog\ProductData;
use App\Data\Catalog\SkuData;
use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Support\Auth\Access;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Catálogo: produto e SKU por linha (código do SKU, produto, unidade de
 * venda, fator de conversão, quantidade mínima e múltiplo). O produto é
 * localizado pelo nome (sem diferenciar maiúsculas), no cadastro ou nas
 * linhas anteriores, e criado quando não existe; código de SKU repetido no
 * arquivo ou já cadastrado é recusado.
 *
 * Colunas opcionais: preco (na tabela informada em tabela_preco ou, sem ela,
 * na tabela padrão ativa; exige pricing.manage) e custo (exige
 * catalog.view_costs). As permissões são conferidas na prévia, linha a
 * linha, e de novo na aplicação pelas Actions de preço e de SKU.
 */
final readonly class ProductsImport implements ImportKind
{
    public function __construct(
        private SaveProduct $products,
        private SaveSku $skus,
        private SetPriceListItem $prices,
        private Access $access,
    ) {}

    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Catálogo (produtos e SKUs)';
    }

    public function permission(): Permission
    {
        return Permission::CatalogManage;
    }

    public function columns(): array
    {
        return ['codigo_sku', 'produto', 'unidade', 'fator_conversao', 'quantidade_minima', 'multiplo'];
    }

    public function optionalColumns(): array
    {
        return ['preco', 'custo', 'tabela_preco'];
    }

    public function validate(array $rows): array
    {
        $codes = array_map(static fn (array $row): string => mb_strtoupper($row['codigo_sku'] ?? ''), $rows);
        $existing = Sku::query()->whereIn('code', array_values(array_filter($codes)))->pluck('code')->map(static fn (mixed $c): string => mb_strtoupper((string) $c))->all();
        $units = UnitOfMeasure::query()->pluck('id', 'code')->mapWithKeys(static fn (mixed $id, mixed $code): array => [mb_strtoupper((string) $code) => (string) $id])->all();
        $seen = [];
        /** @var array<int, list<string>> $errors */
        $errors = [];

        $commercial = new ImportedCommercialData($this->access->can(Permission::PricingManage), $this->access->can(Permission::CatalogViewCosts));

        foreach ($rows as $line => $row) {
            $code = $codes[$line];
            $errors[$line] = [...self::codeErrors($code, $existing, $seen), ...self::fieldErrors($row, $units), ...$commercial->errors($row)];
            $seen[$code] ??= $line;
        }

        return $errors;
    }

    public function apply(array $row, array &$memo): string
    {
        // O cadastro pode ter mudado depois da prévia: a linha é recusada, não a importação.
        $unitId = UnitOfMeasure::query()->whereRaw('upper(code) = ?', [mb_strtoupper($row['unidade'])])->value('id')
            ?? throw new InvalidIntegration('Unidade de medida não cadastrada.');
        $unitId = (string) $unitId;
        $name = trim($row['produto']);
        $key = 'product:'.mb_strtolower($name);
        $productId = $memo[$key] ?? Product::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('created_at')->value('id');
        $product = $productId !== null ? Product::query()->whereKey((string) $productId)->first() : null;
        $product ??= $this->products->handle(null, new ProductData(name: $name, baseUnitId: $unitId));
        $memo[$key] = $product->id;

        $sku = $this->skus->handle($product, null, new SkuData(
            code: mb_strtoupper($row['codigo_sku']),
            salesUnitId: $unitId,
            conversionFactor: BigDecimal::of((self::quantity($row['fator_conversao']) ?? Quantity::of('1'))->toDatabase()),
            minOrderQuantity: self::quantity($row['quantidade_minima']) ?? Quantity::of('1'),
            orderMultiple: self::quantity($row['multiplo']) ?? Quantity::of('1'),
            cost: ImportedCommercialData::money($row['custo'] ?? ''),
        ));

        $price = ImportedCommercialData::money($row['preco'] ?? '');

        if ($price !== null) {
            $list = ImportedCommercialData::priceList($row['tabela_preco'] ?? '')
                ?? throw new InvalidIntegration('Tabela de preço não encontrada ou arquivada.');
            $this->prices->handle($list, $sku, $price);
        }

        return $sku->id;
    }

    private static function quantity(string $value): ?Quantity
    {
        try {
            $quantity = Quantity::parseLocalized(trim($value));
        } catch (InvalidArgumentException|BusinessRuleViolation) {
            // Formato inválido ou fora do intervalo suportado: erro da linha.
            return null;
        }

        return $quantity->isPositive() ? $quantity : null;
    }

    /**
     * @param  array<string>  $existing  códigos já cadastrados
     * @param  array<string, int>  $seen  código => primeira linha do arquivo
     * @return list<string>
     */
    private static function codeErrors(string $code, array $existing, array $seen): array
    {
        return match (true) {
            preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/D', $code) !== 1 => ['Código do SKU inválido (até 40 letras, números, ponto, hífen, barra ou sublinhado).'],
            in_array($code, $existing, true) => ['Código de SKU já cadastrado.'],
            isset($seen[$code]) => ["Código de SKU repetido no arquivo (linha {$seen[$code]})."],
            default => [],
        };
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $units  código da unidade => id
     * @return list<string>
     */
    private static function fieldErrors(array $row, array $units): array
    {
        $errors = [];

        if (($row['produto'] ?? '') === '' || mb_strlen($row['produto']) > 200) {
            $errors[] = 'Nome do produto obrigatório (até 200 caracteres).';
        }

        if (! isset($units[mb_strtoupper($row['unidade'] ?? '')])) {
            $errors[] = 'Unidade de medida não cadastrada.';
        }

        foreach (['fator_conversao' => 'Fator de conversão', 'quantidade_minima' => 'Quantidade mínima', 'multiplo' => 'Múltiplo'] as $field => $label) {
            if (self::quantity($row[$field] ?? '') === null) {
                $errors[] = "{$label} inválido (número positivo).";
            }
        }

        return $errors;
    }
}
