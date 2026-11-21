<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Inventory\Concerns;

use App\Domain\Inventory\Exceptions\InvalidInventoryOperation;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\Permission;
use App\Models\Inventory\Warehouse;
use App\Support\Auth\Access;
use Brick\Math\Exception\MathException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Apoio comum às telas de estoque: permissões para adaptar a tela (a
 * autorização efetiva é das Actions), opções de depósito, termos de busca
 * e quantidades digitadas em pt-BR (sem float).
 *
 * @mixin Component
 */
trait InteractsWithInventory
{
    protected function canManageInventory(): bool
    {
        return app(Access::class)->can(Permission::InventoryManage);
    }

    /**
     * @return Collection<int, Warehouse>
     */
    protected function warehouses(bool $activeOnly = false): Collection
    {
        return Warehouse::query()
            ->when($activeOnly, static fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'is_active', 'allows_sales', 'priority']);
    }

    /**
     * Opções de seleção: id => "CÓDIGO · Nome" (inativos sinalizados).
     *
     * @param  Collection<int, Warehouse>  $warehouses
     * @return array<string, string>
     */
    protected static function warehouseOptions(Collection $warehouses): array
    {
        $options = [];

        foreach ($warehouses as $warehouse) {
            $options[$warehouse->id] = $warehouse->code.' · '.$warehouse->name.($warehouse->is_active ? '' : ' (inativo)');
        }

        return $options;
    }

    /**
     * Quantidade digitada em pt-BR: "1.000" é mil, "2,5" é dois e meio.
     */
    protected function quantityOrError(string $field, string $value): ?Quantity
    {
        try {
            return Quantity::parseLocalized($value);
        } catch (AmountOutOfRange) {
            $this->addError($field, 'Quantidade acima do limite suportado.');

            return null;
        } catch (InvalidArgumentException|MathException) {
            $this->addError($field, 'Informe uma quantidade com até 3 casas decimais, por exemplo 1.000 ou 2,5.');

            return null;
        }
    }

    /**
     * Associa a violação ao campo indicado em details['field'] (quando
     * mapeado) ou ao campo padrão.
     *
     * @param  array<string, string>  $fields  campo da regra => propriedade do componente
     */
    protected function addViolation(BusinessRuleViolation $e, array $fields, string $fallback): void
    {
        $field = $e instanceof InvalidInventoryOperation ? $e->fieldName() : ($e->details()['field'] ?? null);

        $this->addError(is_string($field) && isset($fields[$field]) ? $fields[$field] : $fallback, $e->getMessage());
    }

    /**
     * Escapa curingas de LIKE em termos digitados pelo usuário.
     */
    protected static function likePattern(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }
}
