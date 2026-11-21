<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Arquivo de mídia autorizado para download, pronto para ser transmitido
 * a partir do disco privado. O caminho físico não é exposto ao cliente.
 */
final readonly class MediaDownload
{
    public const string INLINE = 'inline';

    public const string ATTACHMENT = 'attachment';

    public function __construct(
        private Filesystem $disk,
        private string $path,
        public string $contentType,
        public string $disposition,
        public string $fileName,
        public string $fallbackName,
        public int $size,
        public ?string $etag,
    ) {}

    public function isInline(): bool
    {
        return $this->disposition === self::INLINE;
    }

    /**
     * Copia o conteúdo do disco para a saída da resposta, sem carregar o
     * arquivo inteiro em memória.
     */
    public function send(): void
    {
        $stream = $this->disk->readStream($this->path);

        if (! is_resource($stream)) {
            return;
        }

        try {
            fpassthru($stream);
        } finally {
            fclose($stream);
        }
    }
}
