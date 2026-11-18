<?php

declare(strict_types=1);

namespace App\Actions\Inventory\Support;

use App\Data\Inventory\StockBalance;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\InvalidInventoryOperation;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\Quantity;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Models\Inventory\Warehouse;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Apoio comum às movimentações manuais de estoque: validação de
 * quantidades na unidade base do produto, leitura e bloqueio de saldos na
 * ordem do ADR-006 e tradução das restrições do banco (rede de segurança
 * do ADR-005) em regra de negócio.
 */
final class StockGuard
{
    public const int MAX_TEXT = 500;

    /**
     * SKU relido no escopo do tenant corrente (com produto e unidade base):
     * um modelo obtido em outro contexto equivale a registro inexistente.
     */
    public static function sku(Sku $sku): Sku
    {
        return Sku::query()->with('product.baseUnit')->findOrFail($sku->id);
    }

    /**
     * Quantidade positiva com no máximo as casas decimais da unidade base
     * do produto do SKU (relido por self::sku). Devolve a unidade base.
     */
    public static function baseQuantity(Sku $sku, Quantity $quantity, string $field = 'quantity'): UnitOfMeasure
    {
        $unit = $sku->product->baseUnit;

        if (! $quantity->isPositive()) {
            throw InvalidInventoryOperation::field($field, 'Informe uma quantidade maior que zero.');
        }

        if ($quantity->decimalPlaces() > $unit->decimals) {
            throw InvalidInventoryOperation::field($field, $unit->decimals === 0
                ? "A unidade base {$unit->code} não aceita casas decimais."
                : "A unidade base {$unit->code} aceita no máximo {$unit->decimals} casa(s) decimal(is).");
        }

        return $unit;
    }

    /**
     * Texto livre de uma linha (motivo ou observação), com espaços
     * normalizados. Obrigatório: ao menos 5 caracteres.
     */
    public static function text(?string $value, string $field, bool $required): ?string
    {
        $normalized = trim((string) preg_replace('/[\s\p{C}]+/u', ' ', (string) $value));

        if ($normalized === '') {
            if ($required) {
                throw InvalidInventoryOperation::field($field, 'Informe o motivo (ao menos 5 caracteres).');
            }

            return null;
        }

        if ($required && mb_strlen($normalized) < 5) {
            throw InvalidInventoryOperation::field($field, 'Informe o motivo (ao menos 5 caracteres).');
        }

        if (mb_strlen($normalized) > self::MAX_TEXT) {
            throw InvalidInventoryOperation::field($field, 'Use no máximo '.self::MAX_TEXT.' caracteres.');
        }

        return $normalized;
    }

    /**
     * Bloqueia (SELECT ... FOR UPDATE) os saldos do SKU nos depósitos
     * informados, ordenados por (warehouse_id, sku_id). Depósitos ainda sem
     * saldo voltam zerados (a linha nasce com o primeiro movimento).
     *
     * @param  list<string>  $warehouseIds
     * @return array<string, StockBalance> por warehouse_id
     */
    public static function lock(string $skuId, array $warehouseIds): array
    {
        $rows = DB::table('stock_levels')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('sku_id', $skuId)
            ->orderBy('warehouse_id')
            ->orderBy('sku_id')
            ->lockForUpdate()
            ->get(['warehouse_id', 'on_hand', 'reserved'])
            ->keyBy('warehouse_id');

        $balances = [];

        foreach ($warehouseIds as $warehouseId) {
            $row = $rows->get($warehouseId);
            $balances[$warehouseId] = $row === null
                ? StockBalance::empty($warehouseId, $skuId)
                : new StockBalance($warehouseId, $skuId, Quantity::of((string) $row->on_hand), Quantity::of((string) $row->reserved));
        }

        return $balances;
    }

    public static function balance(string $warehouseId, string $skuId): StockBalance
    {
        $row = DB::table('stock_levels')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->first(['on_hand', 'reserved']);

        return $row === null
            ? StockBalance::empty($warehouseId, $skuId)
            : new StockBalance($warehouseId, $skuId, Quantity::of((string) $row->on_hand), Quantity::of((string) $row->reserved));
    }

    /**
     * Saída que deixaria o físico abaixo do reservado (ou negativo).
     */
    public static function assertAvailable(StockBalance $balance, Quantity $outflow, Warehouse $warehouse, Sku $sku, UnitOfMeasure $unit, string $operation): void
    {
        if (! $outflow->greaterThan($balance->available())) {
            return;
        }

        throw new InsufficientStock(
            sprintf(
                '%s de %s %s excede o disponível do SKU %s no depósito %s (físico %s, reservado %s, disponível %s). Informe uma quantidade menor ou libere reservas antes.',
                $operation,
                $outflow->format(),
                $unit->code,
                $sku->code,
                $warehouse->code,
                $balance->onHand->format(),
                $balance->reserved->format(),
                $balance->available()->format(),
            ),
            [
                'field' => 'quantity',
                'sku' => $sku->code,
                'warehouse' => $warehouse->code,
                'required' => $outflow->toDatabase(),
                'available' => $balance->available()->toDatabase(),
            ],
        );
    }

    /**
     * Executa a transação da movimentação. Se, apesar da verificação sob
     * lock, o banco recusar o saldo (CHECK de stock_levels), a recusa vira
     * InsufficientStock, depois do rollback do savepoint.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function transaction(Closure $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (QueryException $e) {
            if ($e->getCode() === '22003') {
                throw new AmountOutOfRange('O saldo resultante excede o limite de quantidade suportado.', ['field' => 'quantity']);
            }

            if ($e->getCode() === '23514' && str_contains($e->getMessage(), 'stock_levels_')) {
                throw new InsufficientStock(
                    'O saldo mudou durante a operação e a movimentação deixaria o estoque físico abaixo do reservado. Consulte o saldo atualizado e tente novamente.',
                    ['field' => 'quantity'],
                );
            }

            throw $e;
        }
    }
}
