<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Catalog;

use App\Data\Catalog\Portal\CatalogFilters;
use App\Services\Catalog\BuyerCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Catálogo do portal: busca, filtro por categoria e paginação. A
 * visibilidade, os preços e a autorização ficam no BuyerCatalog; a tela
 * apenas coordena os filtros (entrada não confiável, normalizada lá).
 */
#[Layout('layouts.app')]
#[Title('Catálogo')]
final class CatalogIndex extends Component
{
    use WithPagination;

    #[Url(as: 'busca', except: '')]
    public string $search = '';

    #[Url(as: 'categoria', except: '')]
    public string $category = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'category');
        $this->resetPage();
    }

    public function paginationView(): string
    {
        return 'livewire.portal.catalog.pagination';
    }

    public function render(BuyerCatalog $catalog): View
    {
        $filters = CatalogFilters::fromInput($this->search, $this->category);
        $page = $catalog->browse($filters, $this->currentPage());

        // Página além da última (filtros mudaram ou URL editada): volta à última existente.
        if ($page->products->isEmpty() && $page->products->total() > 0) {
            $this->setPage($page->products->lastPage());
            $page = $catalog->browse($filters, $page->products->lastPage());
        }

        // Categoria inexistente ou sem produtos visíveis deixa de filtrar.
        if ($this->category !== '' && $page->filters->categoryId === null) {
            $this->category = '';
        }

        return view('livewire.portal.catalog.catalog-index', ['page' => $page]);
    }

    private function currentPage(): int
    {
        $page = filter_var($this->getPage(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($page) ? $page : 1;
    }
}
