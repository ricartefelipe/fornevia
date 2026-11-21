<?php

declare(strict_types=1);

namespace App\Data\Catalog\Portal;

use App\Enums\Catalog\MediaKind;

/**
 * Imagem ou documento de produto visível ao comprador. O arquivo é servido
 * apenas pela rota autorizada de download; disco e caminho físico não
 * fazem parte deste objeto.
 */
final readonly class CatalogMedia
{
    public function __construct(
        public string $id,
        public MediaKind $kind,
        public string $name,
        public string $mimeType,
        public int $sizeBytes,
    ) {}

    public function isImage(): bool
    {
        return $this->kind === MediaKind::Image;
    }

    /**
     * Tamanho legível em formato brasileiro (ex.: "1,5 MB").
     */
    public function formattedSize(): string
    {
        $units = ['bytes', 'KB', 'MB', 'GB'];
        $size = max(0, $this->sizeBytes);
        $index = 0;
        $tenths = $size * 10;

        while ($size >= 1024 && $index < count($units) - 1) {
            $tenths = intdiv($size * 10, 1024);
            $size = intdiv($size, 1024);
            $index++;
        }

        if ($index === 0) {
            return number_format($size, 0, ',', '.').' bytes';
        }

        $integer = intdiv($tenths, 10);
        $fraction = $tenths % 10;

        return number_format($integer, 0, ',', '.').($fraction > 0 ? ','.$fraction : '').' '.$units[$index];
    }
}
