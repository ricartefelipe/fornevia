<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Catalog\Support\CatalogText;
use App\Data\Catalog\UnitOfMeasureData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Catalog\Exceptions\UnitDecimalsInUse;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cadastra ou altera uma unidade de medida (código único no tenant, casas
 * decimais de 0 a 3). Reduzir as casas decimais é recusado quando algum SKU
 * vendido na unidade, ou de produto que a usa como unidade base, deixaria de
 * ter mínimo, múltiplo, faixa de preço ou quantidade convertida representáveis.
 */
final readonly class SaveUnitOfMeasure
{
    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
    ) {}

    public function handle(?UnitOfMeasure $unit, UnitOfMeasureData $data): UnitOfMeasure
    {
        $this->access->authorize(Permission::CatalogManage);

        $code = mb_strtoupper(trim($data->code));

        if (preg_match('/^[A-Z0-9]{1,10}$/D', $code) !== 1) {
            throw InvalidCatalogData::field('code', 'O código da unidade deve ter de 1 a 10 letras ou números, sem espaços (ex.: UN, CX, KG).');
        }

        $name = CatalogText::required($data->name, 'name', 40, 'Informe o nome da unidade (até 40 caracteres).');

        if ($data->decimals < 0 || $data->decimals > 3) {
            throw InvalidCatalogData::field('decimals', 'As casas decimais devem estar entre 0 e 3.');
        }

        try {
            return DB::transaction(function () use ($unit, $code, $name, $data): UnitOfMeasure {
                $unit = $unit !== null
                    ? UnitOfMeasure::query()->lockForUpdate()->findOrFail($unit->id)
                    : new UnitOfMeasure;

                $duplicate = UnitOfMeasure::query()
                    ->where('code', $code)
                    ->when($unit->exists, static fn ($query) => $query->whereKeyNot($unit->id))
                    ->exists();

                if ($duplicate) {
                    throw self::codeInUse($code);
                }

                if ($unit->exists && $data->decimals < $unit->decimals) {
                    $this->assertSkusFit($unit, $data->decimals);
                }

                $creating = ! $unit->exists;
                $before = $creating ? null : self::snapshot($unit);
                $unit->forceFill(['code' => $code, 'name' => $name, 'decimals' => $data->decimals]);

                if (! $creating && ! $unit->isDirty()) {
                    return $unit;
                }

                $unit->save();

                $this->audit->record($creating ? 'unit_of_measure.created' : 'unit_of_measure.updated', $unit, [
                    'before' => $before,
                    'after' => self::snapshot($unit),
                ]);

                return $unit;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::codeInUse($code);
        }
    }

    /**
     * SKUs afetados pela redução de casas decimais: vendidos na unidade
     * (mínimo, múltiplo ou faixa de preço com mais casas) ou de produtos que
     * a usam como unidade base (mínimo × fator e múltiplo × fator precisam
     * ser exatos na unidade base, como exige SaveSku).
     */
    private function assertSkusFit(UnitOfMeasure $unit, int $decimals): void
    {
        $incompatible = Sku::query()
            ->where(static function (Builder $query) use ($unit, $decimals): void {
                $query->where(static function (Builder $sold) use ($unit, $decimals): void {
                    $sold->where('sales_unit_id', $unit->id)
                        ->where(static function (Builder $rule) use ($decimals): void {
                            $rule->whereRaw('min_order_quantity <> trunc(min_order_quantity, ?)', [$decimals])
                                ->orWhereRaw('order_multiple <> trunc(order_multiple, ?)', [$decimals])
                                ->orWhereExists(static fn ($tiers) => $tiers->selectRaw('1')
                                    ->from('price_tiers')
                                    ->join('price_list_items', static function ($join): void {
                                        $join->on('price_list_items.tenant_id', '=', 'price_tiers.tenant_id')
                                            ->on('price_list_items.id', '=', 'price_tiers.price_list_item_id');
                                    })
                                    ->whereColumn('price_list_items.tenant_id', 'skus.tenant_id')
                                    ->whereColumn('price_list_items.sku_id', 'skus.id')
                                    ->whereRaw('price_tiers.min_quantity <> trunc(price_tiers.min_quantity, ?)', [$decimals]));
                        });
                })->orWhere(static function (Builder $based) use ($unit, $decimals): void {
                    $based->whereExists(static fn ($products) => $products->selectRaw('1')
                        ->from('products')
                        ->whereColumn('products.tenant_id', 'skus.tenant_id')
                        ->whereColumn('products.id', 'skus.product_id')
                        ->where('products.base_unit_id', $unit->id))
                        ->where(static function (Builder $rule) use ($decimals): void {
                            $rule->whereRaw('(min_order_quantity * conversion_factor) <> trunc(min_order_quantity * conversion_factor, ?)', [$decimals])
                                ->orWhereRaw('(order_multiple * conversion_factor) <> trunc(order_multiple * conversion_factor, ?)', [$decimals]);
                        });
                });
            })
            ->orderBy('code')
            ->limit(10)
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->all();

        if ($incompatible !== []) {
            throw UnitDecimalsInUse::forSkus(array_values($incompatible));
        }
    }

    private static function codeInUse(string $code): CatalogIdentifierInUse
    {
        return CatalogIdentifierInUse::for('code', $code, "Já existe uma unidade de medida com o código {$code}.");
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(UnitOfMeasure $unit): array
    {
        return ['code' => $unit->code, 'name' => $unit->name, 'decimals' => $unit->decimals];
    }
}
