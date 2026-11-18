<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Enums\Catalog\MediaKind;
use App\Enums\Identity\Permission;
use App\Models\Catalog\ProductMedia;
use App\Support\Auth\Access;
use App\Support\Http\UntrustedId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Download autorizado de imagens e documentos de produto.
 *
 * - O identificador vem do cliente: fora do formato UUID equivale a
 *   registro inexistente.
 * - A mídia é carregada no contexto atual (escopo do tenant e RLS); para o
 *   comprador, além da RLS, exige-se explicitamente mídia visível a
 *   compradores de produto visível no catálogo da empresa.
 * - Equipe precisa de catalog.view; comprador, de portal.catalog.
 * - Mídia não visível, de outro tenant ou sem arquivo no disco: 404.
 */
final readonly class ProductMediaDownloads
{
    /** Imagens exibidas inline; outros tipos (ex.: SVG) só como anexo. */
    private const array INLINE_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];

    public function __construct(
        private Access $access,
        private BuyerCatalogScope $scope,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function find(string $mediaId): ProductMedia
    {
        if ($this->access->isBuyer()) {
            $company = $this->scope->company();
            $id = UntrustedId::uuid($mediaId, ProductMedia::class);

            return ProductMedia::query()
                ->whereKey($id)
                ->where('product_media.visible_to_buyers', true)
                ->whereHas('product', fn (Builder $product): Builder => $this->scope->visibleProducts($product, $company))
                ->firstOrFail();
        }

        $this->access->authorize(Permission::CatalogView);

        return ProductMedia::query()
            ->whereKey(UntrustedId::uuid($mediaId, ProductMedia::class))
            ->firstOrFail();
    }

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function prepare(string $mediaId): MediaDownload
    {
        $media = $this->find($mediaId);
        $disks = config('filesystems.disks');

        if (! is_array($disks) || ! array_key_exists($media->disk, $disks)) {
            Log::warning('Mídia de produto em disco não configurado.', ['media_id' => $media->id, 'disk' => $media->disk]);

            throw (new ModelNotFoundException)->setModel(ProductMedia::class, [$media->id]);
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            Log::warning('Arquivo de mídia de produto ausente no disco.', ['media_id' => $media->id]);

            throw (new ModelNotFoundException)->setModel(ProductMedia::class, [$media->id]);
        }

        $mimeType = strtolower(trim($media->mime_type));
        $inline = $media->kind === MediaKind::Image && in_array($mimeType, self::INLINE_IMAGE_TYPES, true);
        $fileName = MediaFileName::sanitize($media->original_name);

        return new MediaDownload(
            disk: $disk,
            path: $media->path,
            contentType: $inline ? $mimeType : self::attachmentType($mimeType),
            disposition: $inline ? MediaDownload::INLINE : MediaDownload::ATTACHMENT,
            fileName: $fileName,
            fallbackName: MediaFileName::asciiFallback($fileName),
            size: $disk->size($media->path),
            etag: preg_match('/^[0-9a-f]{64}$/D', $media->sha256) === 1 ? $media->sha256 : null,
        );
    }

    /**
     * Tipo informado no upload, quando sintaticamente válido; senão binário
     * genérico. Anexo com nosniff não é interpretado pelo navegador.
     */
    private static function attachmentType(string $mimeType): string
    {
        return preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]{0,63}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}$/D', $mimeType) === 1
            ? $mimeType
            : 'application/octet-stream';
    }
}
