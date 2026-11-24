<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog;

use App\Actions\Catalog\AssignPrivateCatalog;
use App\Actions\Catalog\SavePrivateCatalog;
use App\Actions\Catalog\SetPrivateCatalogProducts;
use App\Actions\Catalog\UnassignPrivateCatalog;
use App\Data\Catalog\PrivateCatalogData;
use App\Data\Catalog\PrivateCatalogTarget;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Cnpj;
use App\Livewire\Staff\Catalog\Concerns\ChecksCatalogPermissions;
use App\Models\Buyers\BuyerCompany;
use App\Models\Buyers\CustomerGroup;
use App\Models\Catalog\PrivateCatalog;
use App\Models\Catalog\PrivateCatalogAssignment;
use App\Models\Catalog\PrivateCatalogProduct;
use App\Models\Catalog\Product;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Catálogos privados: cadastro, produtos incluídos e atribuições a empresas
 * compradoras ou grupos de clientes. Produtos restritos só aparecem no
 * portal para os destinos atribuídos a um catálogo ativo que os contenha.
 */
#[Layout('layouts.app')]
#[Title('Catálogos privados')]
final class PrivateCatalogsIndex extends Component
{
    use ChecksCatalogPermissions;
    use WithPagination;

    private const int SEARCH_LIMIT = 10;

    #[Locked]
    public ?string $editingId = null;

    #[Locked]
    public ?string $selectedId = null;

    public string $code = '';

    public string $name = '';

    public string $description = '';

    public bool $isActive = true;

    public string $productSearch = '';

    public string $companySearch = '';

    public string $groupId = '';

    public ?string $message = null;

    public function edit(string $catalogId): void
    {
        $catalog = $this->catalog($catalogId);
        $this->resetErrorBag();
        $this->message = null;
        $this->editingId = $catalog->id;
        $this->code = $catalog->code;
        $this->name = $catalog->name;
        $this->description = (string) $catalog->description;
        $this->isActive = $catalog->is_active;
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'code', 'name', 'description', 'isActive');
        $this->resetErrorBag();
    }

    public function save(SavePrivateCatalog $save): void
    {
        $this->message = null;

        $this->validate([
            'code' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['boolean'],
        ], [], ['code' => 'código', 'name' => 'nome', 'description' => 'descrição']);

        $editing = $this->editingId !== null;

        try {
            $catalog = $save->handle(
                $editing ? $this->catalog($this->editingId) : null,
                new PrivateCatalogData($this->code, $this->name, $this->description, $this->isActive),
            );
        } catch (CatalogIdentifierInUse $e) {
            $this->addError('code', $e->getMessage());

            return;
        } catch (InvalidCatalogData $e) {
            $this->addError(in_array($e->fieldName(), ['code', 'name', 'description'], true) ? $e->fieldName() : 'code', $e->getMessage());

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addError('code', $e->getMessage());

            return;
        }

        $this->cancel();
        $this->selectedId = $catalog->id;
        $this->message = $editing ? "Catálogo {$catalog->name} atualizado." : "Catálogo {$catalog->name} cadastrado. Inclua produtos e atribua empresas ou grupos.";
    }

    public function manage(string $catalogId): void
    {
        $this->selectedId = $this->catalog($catalogId)->id;
        $this->reset('productSearch', 'companySearch', 'groupId');
        $this->resetPage('produtos');
        $this->resetErrorBag();
        $this->message = null;
    }

    public function closeManage(): void
    {
        $this->reset('selectedId', 'productSearch', 'companySearch', 'groupId');
    }

    public function addProduct(string $productId, SetPrivateCatalogProducts $set): void
    {
        $catalog = $this->selected();
        $product = Product::query()->findOrFail(UntrustedId::uuid($productId, Product::class));
        $this->changeProducts(fn (): array => $set->change($catalog, add: [$product->id]), "Produto {$product->name} incluído.");
    }

    public function removeProduct(string $productId, SetPrivateCatalogProducts $set): void
    {
        $catalog = $this->selected();
        $product = Product::query()->findOrFail(UntrustedId::uuid($productId, Product::class));
        $this->changeProducts(fn (): array => $set->change($catalog, remove: [$product->id]), "Produto {$product->name} removido do catálogo.");
    }

    public function assignCompany(string $companyId, AssignPrivateCatalog $assign): void
    {
        $this->assign($assign, PrivateCatalogTarget::buyerCompany(UntrustedId::uuid($companyId, BuyerCompany::class)));
    }

    public function assignGroup(AssignPrivateCatalog $assign): void
    {
        $this->validate(['groupId' => ['required', 'uuid']], [], ['groupId' => 'grupo de clientes']);
        $this->assign($assign, PrivateCatalogTarget::customerGroup($this->groupId));
        $this->reset('groupId');
    }

    public function unassign(string $assignmentId, UnassignPrivateCatalog $unassign): void
    {
        $catalog = $this->selected();
        $assignment = PrivateCatalogAssignment::query()
            ->where('private_catalog_id', $catalog->id)
            ->findOrFail(UntrustedId::uuid($assignmentId, PrivateCatalogAssignment::class));

        $unassign->handle($assignment);
        $this->message = 'Atribuição removida.';
    }

    public function updatedProductSearch(): void
    {
        $this->resetErrorBag('products');
    }

    public function render(): View
    {
        $catalogs = PrivateCatalog::query()
            ->addSelect([
                'products_count' => PrivateCatalogProduct::query()->selectRaw('count(*)')->whereColumn('private_catalog_products.private_catalog_id', 'private_catalogs.id'),
                'assignments_count' => PrivateCatalogAssignment::query()->selectRaw('count(*)')->whereColumn('private_catalog_assignments.private_catalog_id', 'private_catalogs.id'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25);

        $selected = $this->selectedId !== null ? PrivateCatalog::query()->find($this->selectedId) : null;

        return view('livewire.staff.catalog.private-catalogs-index', [
            'catalogs' => $catalogs,
            'selected' => $selected,
            'catalogProducts' => $selected !== null ? $this->catalogProductsPage($selected) : null,
            'productResults' => $selected !== null ? $this->productResults($selected) : collect(),
            'assignments' => $selected !== null
                ? PrivateCatalogAssignment::query()
                    ->with(['buyerCompany:id,trade_name,legal_name,document', 'customerGroup:id,code,name'])
                    ->where('private_catalog_id', $selected->id)
                    ->orderBy('created_at')
                    ->get()
                : collect(),
            'companyResults' => $selected !== null ? $this->companyResults() : collect(),
            'groups' => $selected !== null ? CustomerGroup::query()->orderBy('name')->get(['id', 'code', 'name']) : collect(),
            'canManage' => $this->canManageCatalog(),
        ]);
    }

    /**
     * @param  callable(): list<string>  $change
     */
    private function changeProducts(callable $change, string $message): void
    {
        try {
            $change();
        } catch (BusinessRuleViolation $e) {
            $this->addError('products', $e->getMessage());

            return;
        }

        $this->message = $message;
    }

    private function assign(AssignPrivateCatalog $assign, PrivateCatalogTarget $target): void
    {
        $catalog = $this->selected();
        $assign->handle($catalog, $target);
        $this->reset('companySearch');
        $this->message = 'Catálogo atribuído.';
    }

    /**
     * @return LengthAwarePaginator<int, Product>
     */
    private function catalogProductsPage(PrivateCatalog $catalog): LengthAwarePaginator
    {
        return Product::query()
            ->whereIn('id', PrivateCatalogProduct::query()->select('product_id')->where('private_catalog_id', $catalog->id))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25, ['id', 'name', 'brand', 'status', 'visibility'], 'produtos');
    }

    /**
     * @return Collection<int, Product>
     */
    private function productResults(PrivateCatalog $catalog): Collection
    {
        $term = trim(mb_substr($this->productSearch, 0, 100));

        if ($term === '') {
            return collect();
        }

        $pattern = self::likePattern($term);

        return Product::query()
            ->whereNotIn('id', PrivateCatalogProduct::query()->select('product_id')->where('private_catalog_id', $catalog->id))
            ->where(static fn (Builder $q) => $q->where('name', 'ilike', $pattern)
                ->orWhere('brand', 'ilike', $pattern)
                ->orWhereHas('skus', static fn (Builder $skus) => $skus->where('code', 'ilike', $pattern)))
            ->orderByRaw("case when visibility = 'restricted' then 0 else 1 end")
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'name', 'brand', 'status', 'visibility']);
    }

    /**
     * @return Collection<int, BuyerCompany>
     */
    private function companyResults(): Collection
    {
        $term = trim(mb_substr($this->companySearch, 0, 100));

        if ($term === '') {
            return collect();
        }

        $pattern = self::likePattern($term);
        $digits = (string) Cnpj::searchKey($term);

        return BuyerCompany::query()
            ->where(static function (Builder $q) use ($pattern, $digits): void {
                $q->where('trade_name', 'ilike', $pattern)->orWhere('legal_name', 'ilike', $pattern);

                if (strlen($digits) >= 4) {
                    $q->orWhere('document', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('trade_name')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'trade_name', 'legal_name', 'document']);
    }

    private function catalog(string $catalogId): PrivateCatalog
    {
        return PrivateCatalog::query()->findOrFail(UntrustedId::uuid($catalogId, PrivateCatalog::class));
    }

    private function selected(): PrivateCatalog
    {
        return PrivateCatalog::query()->findOrFail($this->selectedId);
    }
}
