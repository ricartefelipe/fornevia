<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use Illuminate\Support\Str;

/**
 * Nome de arquivo seguro para exibição e para o cabeçalho
 * Content-Disposition, a partir do nome original enviado no upload
 * (entrada não confiável).
 *
 * - mantém apenas o último segmento (descarta diretórios);
 * - remove caracteres de controle e invisíveis (inclui CR/LF);
 * - substitui caracteres reservados em sistemas de arquivos e cabeçalhos;
 * - remove pontos e espaços das extremidades e limita o tamanho,
 *   preservando a extensão.
 */
final class MediaFileName
{
    public const int MAX_LENGTH = 150;

    public const string FALLBACK = 'arquivo';

    public static function sanitize(string $original): string
    {
        $name = preg_replace('/^.*[\/\\\\]/su', '', $original) ?? '';
        $name = preg_replace('/[\s\p{Z}]+/u', ' ', $name) ?? '';
        $name = preg_replace('/\p{C}+/u', '', $name) ?? '';
        $name = preg_replace('/[<>:"|?*%;]+/u', '_', $name) ?? '';
        $name = trim($name, ' .');

        if ($name === '') {
            return self::FALLBACK;
        }

        return self::limit($name);
    }

    /**
     * Versão ASCII exigida no parâmetro "filename" do Content-Disposition
     * (o nome completo segue em "filename*", codificado em UTF-8).
     */
    public static function asciiFallback(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._ ()-]+/', '_', Str::ascii(self::sanitize($name))) ?? '';
        $ascii = trim(preg_replace('/_{2,}/', '_', $ascii) ?? '', ' ._');

        return $ascii !== '' ? $ascii : self::FALLBACK;
    }

    private static function limit(string $name): string
    {
        if (mb_strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);

        if ($extension === '' || mb_strlen($extension) > 10) {
            return rtrim(mb_substr($name, 0, self::MAX_LENGTH), ' .');
        }

        $base = mb_substr($name, 0, mb_strlen($name) - mb_strlen($extension) - 1);

        return rtrim(mb_substr($base, 0, self::MAX_LENGTH - mb_strlen($extension) - 1), ' .').'.'.$extension;
    }
}
