<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Services\Catalog\ProductMediaDownloads;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download de mídias de produto (equipe e portal do comprador). O arquivo
 * é transmitido do disco privado; não há link público de storage.
 *
 * Imagens seguras são exibidas inline; documentos (e qualquer outro tipo)
 * sempre como anexo. A resposta não pode ser interpretada como página
 * (nosniff, CSP sandbox) nem guardada em caches compartilhados.
 */
final class ProductMediaController
{
    public function __construct(private readonly ProductMediaDownloads $downloads) {}

    public function download(Request $request, string $media): StreamedResponse
    {
        $file = $this->downloads->prepare($media);

        $response = new StreamedResponse(static function () use ($file): void {
            $file->send();
        }, 200, [
            'Content-Type' => $file->contentType,
            'Content-Length' => (string) $file->size,
            'Content-Disposition' => HeaderUtils::makeDisposition($file->disposition, $file->fileName, $file->fallbackName),
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-origin',
            // Revalidação a cada uso: a autorização é refeita em toda
            // requisição, e o ETag evita retransmitir o arquivo inalterado.
            'Cache-Control' => 'private, no-cache',
        ]);

        if ($file->etag !== null) {
            $response->setEtag($file->etag);
            $response->isNotModified($request);
        }

        return $response;
    }
}
