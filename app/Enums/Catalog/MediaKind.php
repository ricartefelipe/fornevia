<?php

declare(strict_types=1);

namespace App\Enums\Catalog;

enum MediaKind: string
{
    case Image = 'image';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Image => 'Imagem',
            self::Document => 'Documento',
        };
    }
}
