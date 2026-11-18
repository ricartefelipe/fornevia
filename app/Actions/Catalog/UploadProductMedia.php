<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Domain\Catalog\Exceptions\InvalidMediaFile;
use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Identity\Permission;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Services\Audit\AuditRecorder;
use App\Support\Auth\Access;
use App\Support\Tenancy\CurrentContext;
use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Anexa imagem ou documento a um produto, no disco privado.
 *
 * O tipo é determinado pelo conteúdo (finfo), nunca pela extensão ou pelo
 * tipo informado pelo navegador: imagens JPEG, PNG ou WebP até 5 MB;
 * documentos PDF até 10 MB. O arquivo é gravado em
 * tenants/{tenant}/products/{produto}/{uuid}.{ext} com SHA-256 e tamanho
 * registrados; se a transação for desfeita, o arquivo gravado é removido.
 */
final readonly class UploadProductMedia
{
    public const string DISK = 'private';

    public const int MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const int MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    /** @var array<string, array{0: MediaKind, 1: string}> tipo detectado => [tipo de mídia, extensão] */
    private const array TYPES = [
        'image/jpeg' => [MediaKind::Image, 'jpg'],
        'image/png' => [MediaKind::Image, 'png'],
        'image/webp' => [MediaKind::Image, 'webp'],
        'application/pdf' => [MediaKind::Document, 'pdf'],
    ];

    public function __construct(
        private Access $access,
        private AuditRecorder $audit,
        private CurrentContext $context,
    ) {}

    public function handle(Product $product, UploadedFile $file, MediaKind $kind, bool $visibleToBuyers = true): ProductMedia
    {
        $this->access->authorize(Permission::CatalogManage);

        $source = $file->getRealPath();

        if (! is_string($source) || ! is_file($source) || ! is_readable($source)) {
            throw InvalidMediaFile::empty();
        }

        $size = filesize($source);

        if ($size === false || $size === 0) {
            throw InvalidMediaFile::empty();
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->file($source);
        $detected = is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
        [$detectedKind, $extension] = self::TYPES[$detected] ?? throw InvalidMediaFile::unsupportedType($detected);

        if ($detectedKind !== $kind) {
            throw InvalidMediaFile::kindMismatch($kind->label(), $detected);
        }

        $limit = $kind === MediaKind::Image ? self::MAX_IMAGE_BYTES : self::MAX_DOCUMENT_BYTES;

        if ($size > $limit) {
            throw InvalidMediaFile::tooLarge($limit);
        }

        $sha256 = hash_file('sha256', $source);

        if ($sha256 === false) {
            throw InvalidMediaFile::empty();
        }

        $originalName = self::originalName($file->getClientOriginalName(), $extension);

        return DB::transaction(function () use ($product, $source, $kind, $visibleToBuyers, $detected, $extension, $size, $sha256, $originalName): ProductMedia {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);

            if ($locked->status === ProductStatus::Archived) {
                throw CatalogItemArchived::product();
            }

            $id = (string) Str::uuid7();
            $path = sprintf('tenants/%s/products/%s/%s.%s', $this->context->tenantId(), $locked->id, $id, $extension);
            $disk = Storage::disk(self::DISK);

            // Registrado antes da gravação: qualquer falha a partir daqui (ou um
            // rollback posterior da transação externa) remove o arquivo.
            DB::afterRollBack(static fn () => Storage::disk(self::DISK)->delete($path));

            $stream = fopen($source, 'rb');

            if ($stream === false) {
                throw InvalidMediaFile::empty();
            }

            try {
                $disk->writeStream($path, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            $media = new ProductMedia;
            $media->forceFill([
                'id' => $id,
                'product_id' => $locked->id,
                'kind' => $kind,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => $originalName,
                'mime_type' => $detected,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'visible_to_buyers' => $visibleToBuyers,
                'sort_order' => self::nextSortOrder($locked),
                'uploaded_by_user_id' => $this->context->require()->userId,
            ])->save();

            $this->audit->record('product_media.uploaded', $media, [
                'product_id' => $locked->id,
                'kind' => $kind->value,
                'mime_type' => $detected,
                'size_bytes' => $size,
                'sha256' => $sha256,
                'original_name' => $originalName,
                'visible_to_buyers' => $visibleToBuyers,
            ]);

            return $media;
        });
    }

    /**
     * Nome exibido no download: sem diretórios nem caracteres de controle,
     * até 200 caracteres e com a extensão do tipo detectado.
     */
    private static function originalName(string $clientName, string $extension): string
    {
        $base = str_replace('\\', '/', $clientName);
        $base = basename($base);
        $base = trim((string) preg_replace('/[\p{C}"<>|:*?]+/u', '', $base));
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $stem = trim(mb_substr($stem, 0, 190), " .\t");

        return ($stem !== '' ? $stem : 'arquivo').'.'.$extension;
    }

    private static function nextSortOrder(Product $product): int
    {
        $max = ProductMedia::query()->where('product_id', $product->id)->max('sort_order');

        return is_numeric($max) ? (int) $max + 1 : 0;
    }
}
