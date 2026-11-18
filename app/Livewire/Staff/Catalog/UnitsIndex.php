<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog;

use App\Actions\Catalog\SaveUnitOfMeasure;
use App\Data\Catalog\UnitOfMeasureData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Catalog\Exceptions\UnitDecimalsInUse;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Catalog\Concerns\ChecksCatalogPermissions;
use App\Models\Catalog\Sku;
use App\Models\Catalog\UnitOfMeasure;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Unidades de medida')]
final class UnitsIndex extends Component
{
    use ChecksCatalogPermissions;
    use WithPagination;

    #[Locked]
    public ?string $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $decimals = '0';

    public ?string $message = null;

    public function edit(string $unitId): void
    {
        $unit = UnitOfMeasure::query()->findOrFail(UntrustedId::uuid($unitId, UnitOfMeasure::class));
        $this->resetErrorBag();
        $this->message = null;
        $this->editingId = $unit->id;
        $this->code = $unit->code;
        $this->name = $unit->name;
        $this->decimals = (string) $unit->decimals;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'code', 'name', 'decimals');
        $this->resetErrorBag();
    }

    public function save(SaveUnitOfMeasure $save): void
    {
        $this->message = null;

        $this->validate([
            'code' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:40'],
            'decimals' => ['required', 'integer', 'min:0', 'max:3'],
        ], [], ['code' => 'código', 'name' => 'nome', 'decimals' => 'casas decimais']);

        $editing = $this->editingId !== null;

        try {
            $unit = $save->handle(
                $editing ? UnitOfMeasure::query()->findOrFail($this->editingId) : null,
                new UnitOfMeasureData($this->code, $this->name, (int) $this->decimals),
            );
        } catch (CatalogIdentifierInUse $e) {
            $this->addError('code', $e->getMessage());

            return;
        } catch (UnitDecimalsInUse $e) {
            $this->addError('decimals', $e->getMessage());

            return;
        } catch (InvalidCatalogData $e) {
            $this->addError(in_array($e->fieldName(), ['code', 'name', 'decimals'], true) ? $e->fieldName() : 'code', $e->getMessage());

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addError('code', $e->getMessage());

            return;
        }

        $this->cancel();
        $this->message = $editing ? "Unidade {$unit->code} atualizada." : "Unidade {$unit->code} cadastrada.";
    }

    public function render(): View
    {
        return view('livewire.staff.catalog.units-index', [
            'units' => UnitOfMeasure::query()
                ->addSelect(['skus_count' => Sku::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('skus.sales_unit_id', 'units_of_measure.id')])
                ->orderBy('code')
                ->orderBy('id')
                ->paginate(25),
            'canManage' => $this->canManageCatalog(),
        ]);
    }
}
