<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Arquivo de mídia recusado pelo tipo detectado no conteúdo ou pelo tamanho.
 */
final class InvalidMediaFile extends BusinessRuleViolation
{
    public static function unsupportedType(string $detected): self
    {
        return new self(
            'Tipo de arquivo não permitido. Envie imagens JPEG, PNG ou WebP, ou documentos PDF.',
            ['detected_type' => $detected],
        );
    }

    public static function kindMismatch(string $expectedLabel, string $detected): self
    {
        return new self(
            "O conteúdo do arquivo não corresponde ao tipo escolhido ({$expectedLabel}).",
            ['detected_type' => $detected],
        );
    }

    public static function tooLarge(int $limitBytes): self
    {
        $megabytes = intdiv($limitBytes, 1024 * 1024);

        return new self("O arquivo excede o limite de {$megabytes} MB.", ['limit_bytes' => $limitBytes]);
    }

    public static function empty(): self
    {
        return new self('O arquivo enviado está vazio ou não pôde ser lido.');
    }

    public function errorCode(): string
    {
        return 'invalid_media_file';
    }
}
