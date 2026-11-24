<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Actions\Catalog\SaveProduct;
use App\Actions\Catalog\SaveSku;
use App\Data\Catalog\ProductData;
use App\Data\Catalog\SkuData;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Product;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Catálogo: produto e SKU por linha (código do SKU, produto, unidade de
 * venda, fator de conversão, quantidade mínima e múltiplo). O produto é
 * localizado pelo nome (sem diferenciar maiúsculas), no cadastro ou nas
 * linhas anteriores, e criado quando não existe; código de SKU repetido no
 * arquivo ou já cadastrado é recusado.
 */
final readonly class ProductsImport implements ImportKind
{
    public function __construct(private SaveProduct $products, private SaveSku $skus) {}

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

    public function validate(array $rows): array
    {
        $codes = array_map(static fn (array $row): string => mb_strtoupper($row['codigo_sku'] ?? ''), $rows);
        $existing = Sku::query()->whereIn('code', array_values(array_filter($codes)))->pluck('code')->map(static fn (mixed $c): string => mb_strtoupper((string) $c))->all();
        $units = UnitOfMeasure::query()->pluck('id', 'code')->mapWithKeys(static fn (mixed $id, mixed $code): array => [mb_strtoupper((string) $code) => (string) $id])->all();
        $seen = [];
        /** @var array<int, list<string>> $errors */
        $errors = [];

        foreach ($rows as $line => $row) {
            /** @var list<string> $rowErrors */
            $rowErrors = [];
            $code = $codes[$line];

            if (preg_match('/^[A-Z0-9][A-Z0-9._\/-]{0,39}$/D', $code) !== 1) {
                $rowErrors[] = 'Código do SKU inválido (até 40 letras, números, ponto, hífen, barra ou sublinhado).';
            } elseif (in_array($code, $existing, true)) {
                $rowErrors[] = 'Código de SKU já cadastrado.';
            } elseif (isset($seen[$code])) {
                $rowErrors[] = "Código de SKU repetido no arquivo (linha {$seen[$code]}).";
            } else {
                $seen[$code] = $line;
            }

            if (($row['produto'] ?? '') === '' || mb_strlen($row['produto']) > 200) {
                $rowErrors[] = 'Nome do produto obrigatório (até 200 caracteres).';
            }

            if (! isset($units[mb_strtoupper($row['unidade'] ?? '')])) {
                $rowErrors[] = 'Unidade de medida não cadastrada.';
            }

            foreach (['fator_conversao' => 'Fator de conversão', 'quantidade_minima' => 'Quantidade mínima', 'multiplo' => 'Múltiplo'] as $field => $label) {
                if (self::quantity($row[$field] ?? '') === null) {
                    $rowErrors[] = "{$label} inválido (número positivo).";
                }
            }

            $errors[$line] = $rowErrors;
        }

        return $errors;
    }

    public function apply(array $row, array &$memo): string
    {
        $unitId = (string) UnitOfMeasure::query()->whereRaw('upper(code) = ?', [mb_strtoupper($row['unidade'])])->value('id');
        $name = trim($row['produto']);
        $key = 'product:'.mb_strtolower($name);
        $productId = $memo[$key] ?? Product::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->orderBy('created_at')->value('id');
        $product = $productId !== null
            ? Product::query()->whereKey((string) $productId)->firstOrFail()
            : $this->products->handle(null, new ProductData(name: $name, baseUnitId: $unitId));
        $memo[$key] = $product->id;

        return $this->skus->handle($product, null, new SkuData(
            code: mb_strtoupper($row['codigo_sku']),
            salesUnitId: $unitId,
            conversionFactor: BigDecimal::of((self::quantity($row['fator_conversao']) ?? Quantity::of('1'))->toDatabase()),
            minOrderQuantity: self::quantity($row['quantidade_minima']) ?? Quantity::of('1'),
            orderMultiple: self::quantity($row['multiplo']) ?? Quantity::of('1'),
        ))->id;
    }

    private static function quantity(string $value): ?Quantity
    {
        try {
            $quantity = Quantity::parseLocalized(trim($value));
        } catch (InvalidArgumentException) {
            return null;
        }

        return $quantity->isPositive() ? $quantity : null;
    }
}
