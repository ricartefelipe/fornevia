<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog;

use App\Actions\Catalog\ChangeProductStatus;
use App\Actions\Catalog\DeleteProduct;
use App\Actions\Catalog\SaveProduct;
use App\Data\Catalog\ProductData;
use App\Domain\Catalog\Exceptions\CatalogIdentifierInUse;
use App\Domain\Catalog\Exceptions\InvalidCatalogData;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Livewire\Staff\Catalog\Concerns\ChecksCatalogPermissions;
use App\Models\Catalog\Category;
use App\Models\Catalog\Product;
use App\Models\Catalog\UnitOfMeasure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Cadastro e edição de produto: dados, situação e exclusão. SKUs e mídias
 * ficam em componentes próprios na mesma página. O identificador do
 * produto é bloqueado contra alteração pelo cliente e o registro é sempre
 * recarregado no escopo do tenant.
 */
#[Layout('layouts.app')]
final class ProductEditor extends Component
{
    use ChecksCatalogPermissions;

    /** @var array<string, string> campo da Action => propriedade do formulário */
    private const array FIELDS = [
        'name' => 'name',
        'slug' => 'slug',
        'brand' => 'brand',
        'description' => 'description',
        'ncm' => 'ncm',
        'base_unit_id' => 'baseUnitId',
        'category_id' => 'categoryId',
        'backorder_lead_days' => 'backorderLeadDays',
    ];

    #[Locked]
    public ?string $productId = null;

    public string $name = '';

    public string $slug = '';

    public string $brand = '';

    public string $description = '';

    public string $ncm = '';

    public string $categoryId = '';

    public string $baseUnitId = '';

    public string $visibility = 'public';

    public bool $allowBackorder = false;

    public string $backorderLeadDays = '';

    public ?string $message = null;

    public function mount(?Product $product = null): void
    {
        if ($product === null) {
            return;
        }

        $this->productId = $product->id;
        $this->name = $product->name;
        $this->slug = $product->slug;
        $this->brand = (string) $product->brand;
        $this->description = (string) $product->description;
        $this->ncm = (string) $product->ncm;
        $this->categoryId = (string) $product->category_id;
        $this->baseUnitId = $product->base_unit_id;
        $this->visibility = $product->visibility->value;
        $this->allowBackorder = $product->allow_backorder;
        $this->backorderLeadDays = $product->backorder_lead_days !== null ? (string) $product->backorder_lead_days : '';
    }

    public function save(SaveProduct $save): void
    {
        $this->message = null;

        $this->validate([
            'name' => ['required', 'string', 'max:200'],
            'slug' => ['nullable', 'string', 'max:220'],
            'brand' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ncm' => ['nullable', 'string', 'max:10'],
            'categoryId' => ['nullable', 'uuid'],
            'baseUnitId' => ['required', 'uuid'],
            'visibility' => ['required', Rule::enum(ProductVisibility::class)],
            'allowBackorder' => ['boolean'],
            'backorderLeadDays' => ['nullable', 'integer', 'min:1', 'max:'.SaveProduct::MAX_LEAD_DAYS],
        ], [], [
            'name' => 'nome',
            'slug' => 'endereço (slug)',
            'brand' => 'marca',
            'description' => 'descrição',
            'ncm' => 'NCM',
            'categoryId' => 'categoria',
            'baseUnitId' => 'unidade base',
            'visibility' => 'visibilidade',
            'backorderLeadDays' => 'prazo de encomenda',
        ]);

        $creating = $this->productId === null;

        try {
            $product = $save->handle($creating ? null : $this->product(), new ProductData(
                name: $this->name,
                baseUnitId: $this->baseUnitId,
                slug: $this->slug !== '' ? $this->slug : null,
                brand: $this->brand,
                description: $this->description,
                ncm: $this->ncm,
                categoryId: $this->categoryId !== '' ? $this->categoryId : null,
                visibility: ProductVisibility::from($this->visibility),
                allowBackorder: $this->allowBackorder,
                backorderLeadDays: $this->allowBackorder && $this->backorderLeadDays !== '' ? (int) $this->backorderLeadDays : null,
            ));
        } catch (InvalidCatalogData $e) {
            $this->addError(self::FIELDS[$e->fieldName()] ?? 'name', $e->getMessage());

            return;
        } catch (CatalogIdentifierInUse $e) {
            $this->addError('slug', $e->getMessage());

            return;
        } catch (BusinessRuleViolation $e) {
            $this->addError('name', $e->getMessage());

            return;
        }

        if ($creating) {
            session()->flash('success', 'Produto cadastrado como rascunho. Cadastre os SKUs para ativá-lo.');
            $this->redirectRoute('catalog.products.edit', ['product' => $product->id]);

            return;
        }

        $this->slug = $product->slug;
        $this->message = 'Dados do produto salvos.';
    }

    public function changeStatus(string $status, ChangeProductStatus $change): void
    {
        $this->message = null;
        $target = ProductStatus::tryFrom($status);

        if ($target === null) {
            $this->addError('status', 'Situação inválida.');

            return;
        }

        try {
            $product = $change->handle($this->product(), $target);
        } catch (BusinessRuleViolation $e) {
            $this->addError('status', $e->getMessage());

            return;
        }

        $this->message = 'Situação alterada para '.mb_strtolower($product->status->label()).'.';
    }

    public function delete(DeleteProduct $delete): void
    {
        $this->message = null;
        $product = $this->product();

        try {
            $delete->handle($product);
        } catch (BusinessRuleViolation $e) {
            $this->addError('delete', $e->getMessage());

            return;
        }

        session()->flash('success', "Produto {$product->name} excluído.");
        $this->redirectRoute('catalog.products');
    }

    /**
     * Os componentes de SKUs e mídias avisam alterações para que a situação
     * e os indicadores do produto sejam renderizados novamente.
     */
    #[On('catalog-product-changed')]
    public function refreshProduct(): void {}

    public function render(): View
    {
        $product = $this->productId !== null
            ? Product::query()->with(['baseUnit:id,code,name'])->withCount('skus')->findOrFail($this->productId)
            : null;

        return view('livewire.staff.catalog.product-editor', [
            'product' => $product,
            'transitions' => $product !== null ? ChangeProductStatus::allowedFrom($product->status) : [],
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'units' => UnitOfMeasure::query()->orderBy('code')->get(['id', 'code', 'name']),
            'visibilities' => ProductVisibility::cases(),
            'canManage' => $this->canManageCatalog(),
        ])->title($product !== null ? 'Produto: '.$product->name : 'Novo produto');
    }

    private function product(): Product
    {
        return Product::query()->findOrFail($this->productId);
    }
}
