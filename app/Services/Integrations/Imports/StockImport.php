<?php

declare(strict_types=1);

namespace App\Services\Integrations\Imports;

use App\Actions\Inventory\ReceiveStock;
use App\Actions\Inventory\Support\StockGuard;
use App\Domain\Integrations\Exceptions\InvalidIntegration;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Sku;
use App\Models\Inventory\Warehouse;
use InvalidArgumentException;

/**
 * Entradas de estoque: depósito (código), SKU (código) e quantidade na
 * unidade base (com as casas decimais que ela aceita). Cada linha aplicada
 * é uma entrada no ledger, uma única vez.
 */
final readonly class StockImport implements ImportKind
{
    public function __construct(private ReceiveStock $receive) {}

    public function key(): string
    {
        return 'stock';
    }

    public function label(): string
    {
        return 'Entradas de estoque';
    }

    public function permission(): Permission
    {
        return Permission::InventoryManage;
    }

    public function columns(): array
    {
        return ['deposito', 'codigo_sku', 'quantidade'];
    }

    public function optionalColumns(): array
    {
        return [];
    }

    public function validate(array $rows): array
    {
        $warehouses = Warehouse::query()->where('is_active', true)->pluck('id', 'code')->mapWithKeys(static fn (mixed $id, mixed $code): array => [mb_strtoupper((string) $code) => (string) $id])->all();
        $skus = Sku::query()->with('product.baseUnit')->whereIn('code', array_values(array_map(static fn (array $r): string => mb_strtoupper($r['codigo_sku'] ?? ''), $rows)))->get()->keyBy('code')->all();
        /** @var array<int, list<string>> $errors */
        $errors = [];

        foreach ($rows as $line => $row) {
            /** @var list<string> $rowErrors */
            $rowErrors = [];
            $sku = $skus[mb_strtoupper($row['codigo_sku'] ?? '')] ?? null;

            if (! isset($warehouses[mb_strtoupper($row['deposito'] ?? '')])) {
                $rowErrors[] = 'Depósito não cadastrado ou inativo.';
            }

            if ($sku === null) {
                $rowErrors[] = 'SKU não cadastrado.';
            }

            try {
                $quantity = Quantity::parseLocalized(trim($row['quantidade'] ?? ''));

                if (! $quantity->isPositive()) {
                    $rowErrors[] = 'Quantidade deve ser positiva.';
                } elseif ($sku !== null) {
                    // Mesma regra da entrada manual: casas decimais da unidade base.
                    StockGuard::baseQuantity($sku, $quantity);
                }
            } catch (InvalidArgumentException) {
                $rowErrors[] = 'Quantidade inválida.';
            } catch (BusinessRuleViolation $e) {
                $rowErrors[] = $e->getMessage();
            }

            $errors[$line] = $rowErrors;
        }

        return $errors;
    }

    public function apply(array $row, array &$memo): string
    {
        // O cadastro pode ter mudado depois da prévia: a linha é recusada, não a importação.
        $warehouse = Warehouse::query()->where('is_active', true)->whereRaw('upper(code) = ?', [mb_strtoupper($row['deposito'])])->first()
            ?? throw new InvalidIntegration('Depósito não cadastrado ou inativo.');
        $sku = Sku::query()->whereRaw('upper(code) = ?', [mb_strtoupper($row['codigo_sku'])])->first()
            ?? throw new InvalidIntegration('SKU não cadastrado.');
        $this->receive->handle($warehouse, $sku, Quantity::parseLocalized(trim($row['quantidade'])), 'Importação de estoque');

        return $sku->id;
    }
}
