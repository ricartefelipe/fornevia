<?php

declare(strict_types=1);

namespace App\Enums\Catalog;

enum ProductStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Ativo',
            self::Inactive => 'Inativo',
            self::Archived => 'Arquivado',
        };
    }
}
