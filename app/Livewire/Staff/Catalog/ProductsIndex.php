<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog;

use App\Enums\Catalog\ProductStatus;
use App\Livewire\Staff\Catalog\Concerns\ChecksCatalogPermissions;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Lista de produtos da equipe: busca por nome, marca ou código de SKU e
 * filtros de categoria e situação. Filtros são entrada não confiável:
 * valores fora do formato esperado são ignorados.
 */
#[Layout('layouts.app')]
#[Title('Catálogo')]
final class ProductsIndex extends Component
{
    use ChecksCatalogPermissions;
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'categoria', except: '')]
    public string $categoryId = '';

    #[Url(as: 'situacao', except: '')]
    public string $status = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'categoryId', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'categoryId', 'status');
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim(mb_substr($this->search, 0, 100));
        $status = ProductStatus::tryFrom($this->status);
        $categoryId = Str::isUuid($this->categoryId) ? $this->categoryId : null;

        $products = Product::query()
            ->with(['category:id,name', 'baseUnit:id,code'])
            ->withCount('skus')
            ->when($term !== '', static function (Builder $query) use ($term): void {
                $pattern = self::likePattern($term);
                $query->where(static fn (Builder $q) => $q
                    ->where('name', 'ilike', $pattern)
                    ->orWhere('brand', 'ilike', $pattern)
                    ->orWhereHas('skus', static fn (Builder $skus) => $skus->where('code', 'ilike', $pattern)));
            })
            ->when($categoryId !== null, static fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($status !== null, static fn (Builder $query) => $query->where('status', $status?->value))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.staff.catalog.products-index', [
            'products' => $products,
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'statuses' => ProductStatus::cases(),
            'filtered' => $term !== '' || $status !== null || $categoryId !== null,
            'canManage' => $this->canManageCatalog(),
        ]);
    }
}
