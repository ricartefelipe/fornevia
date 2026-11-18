<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog;

use App\Actions\Catalog\SaveCategory;
use App\Data\Catalog\CategoryData;
use App\Domain\Catalog\Exceptions\CategoryCycle;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Livewire\Staff\Catalog\Concerns\ChecksCatalogPermissions;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Categorias do catálogo')]
final class CategoriesIndex extends Component
{
    use ChecksCatalogPermissions;
    use WithPagination;

    #[Locked]
    public ?string $editingId = null;

    public string $name = '';

    public string $parentId = '';

    public string $sortOrder = '0';

    public ?string $message = null;

    public function edit(string $categoryId): void
    {
        $category = Category::query()->findOrFail(UntrustedId::uuid($categoryId, Category::class));
        $this->resetErrorBag();
        $this->message = null;
        $this->editingId = $category->id;
        $this->name = $category->name;
        $this->parentId = (string) $category->parent_id;
        $this->sortOrder = (string) $category->sort_order;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'name', 'parentId', 'sortOrder');
        $this->resetErrorBag();
    }

    public function save(SaveCategory $save): void
    {
        $this->message = null;

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'parentId' => ['nullable', 'uuid'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [], ['name' => 'nome', 'parentId' => 'categoria superior', 'sortOrder' => 'ordem de exibição']);

        $editing = $this->editingId !== null;

        try {
            $category = $save->handle(
                $editing ? Category::query()->findOrFail($this->editingId) : null,
                new CategoryData($this->name, $this->parentId !== '' ? $this->parentId : null, (int) $this->sortOrder),
            );
        } catch (CategoryCycle $e) {
            $this->addError('parentId', $e->getMessage());

            return;
        } catch (InvalidCatalogData $e) {
            $this->addError($e->fieldName() === 'sort_order' ? 'sortOrder' : 'name', $e->getMessage());

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        $this->cancel();
        $this->message = $editing ? "Categoria {$category->name} atualizada." : "Categoria {$category->name} cadastrada.";
    }

    public function render(): View
    {
        $categories = Category::query()
            ->with('parent:id,name')
            ->addSelect(['products_count' => Product::query()
                ->selectRaw('count(*)')
                ->whereColumn('products.category_id', 'categories.id')])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);

        return view('livewire.staff.catalog.categories-index', [
            'categories' => $categories,
            'parents' => Category::query()
                ->when($this->editingId !== null, fn ($query) => $query->whereKeyNot($this->editingId))
                ->orderBy('name')
                ->get(['id', 'name']),
            'canManage' => $this->canManageCatalog(),
        ]);
    }
}
