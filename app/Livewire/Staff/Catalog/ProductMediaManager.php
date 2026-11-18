<?php

declare(strict_types=1);

namespace App\Livewire\Staff\Catalog;

use App\Actions\Catalog\DeleteProductMedia;
use App\Actions\Catalog\UploadProductMedia;
use App\Domain\Shared\BusinessRuleViolation;
use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\ProductStatus;
use App\Livewire\Staff\Catalog\Concerns\ChecksCatalogPermissions;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Imagens e documentos de um produto. O tipo real do arquivo é verificado
 * pela Action (conteúdo), não pelo navegador. O download usa a rota
 * autorizada catalog.media.download quando ela estiver registrada.
 */
final class ProductMediaManager extends Component
{
    use ChecksCatalogPermissions;
    use WithFileUploads {
        _startUpload as private startTemporaryUpload;
        _finishUpload as private finishTemporaryUpload;
    }

    #[Locked]
    public string $productId = '';

    public ?TemporaryUploadedFile $upload = null;

    public string $mediaKind = 'image';

    public bool $visibleToBuyers = true;

    public ?string $message = null;

    public function mount(string $productId): void
    {
        $this->productId = Product::query()->findOrFail(UntrustedId::uuid($productId, Product::class))->id;
    }

    /**
     * O envio temporário grava o arquivo antes da Action: só quem pode
     * alterar o catálogo recebe a URL de envio, e apenas para o campo do
     * formulário de produto não arquivado.
     */
    public function _startUpload(mixed $name, mixed $fileInfo, mixed $isMultiple): void
    {
        $this->authorizeTemporaryUpload($name);
        $this->startTemporaryUpload($name, $fileInfo, $isMultiple);
    }

    public function _finishUpload(mixed $name, mixed $tmpPath, mixed $isMultiple, mixed $append = true): void
    {
        $this->authorizeTemporaryUpload($name);
        $this->finishTemporaryUpload($name, $tmpPath, $isMultiple, $append);
    }

    public function uploadMedia(UploadProductMedia $uploader): void
    {
        $this->message = null;

        $this->validate([
            'upload' => ['required', 'file', 'max:10240'],
            'mediaKind' => ['required', Rule::enum(MediaKind::class)],
            'visibleToBuyers' => ['boolean'],
        ], [], ['upload' => 'arquivo', 'mediaKind' => 'tipo', 'visibleToBuyers' => 'visível ao comprador']);

        $file = $this->upload;

        if (! $file instanceof TemporaryUploadedFile) {
            $this->addError('upload', 'Selecione um arquivo.');

            return;
        }

        try {
            $media = $uploader->handle($this->product(), $file, MediaKind::from($this->mediaKind), $this->visibleToBuyers);
        } catch (BusinessRuleViolation $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        $file->delete();
        $this->reset('upload');
        $this->message = "Arquivo {$media->original_name} anexado.";
        $this->dispatch('catalog-product-changed');
    }

    public function deleteMedia(string $mediaId, DeleteProductMedia $delete): void
    {
        $this->message = null;
        $media = ProductMedia::query()
            ->where('product_id', $this->productId)
            ->findOrFail(UntrustedId::uuid($mediaId, ProductMedia::class));

        try {
            $delete->handle($media);
        } catch (BusinessRuleViolation $e) {
            $this->addError('media', $e->getMessage());

            return;
        }

        $this->message = "Arquivo {$media->original_name} removido.";
        $this->dispatch('catalog-product-changed');
    }

    public function render(): View
    {
        $product = $this->product();

        return view('livewire.staff.catalog.product-media-manager', [
            'media' => ProductMedia::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('created_at')->get(),
            'kinds' => MediaKind::cases(),
            'canEdit' => $this->canManageCatalog() && $product->status !== ProductStatus::Archived,
            'downloads' => Route::has('catalog.media.download'),
            'imageLimitMb' => intdiv(UploadProductMedia::MAX_IMAGE_BYTES, 1024 * 1024),
            'documentLimitMb' => intdiv(UploadProductMedia::MAX_DOCUMENT_BYTES, 1024 * 1024),
        ]);
    }

    private function product(): Product
    {
        return Product::query()->findOrFail($this->productId);
    }

    private function authorizeTemporaryUpload(mixed $name): void
    {
        abort_unless($name === 'upload' && $this->canManageCatalog() && $this->product()->status !== ProductStatus::Archived, 403);
    }
}
