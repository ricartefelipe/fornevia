<?php

declare(strict_types=1);

namespace App\Enums\Quotes;

/**
 * Visibilidade do comentário: compartilhado com o comprador ou nota interna da equipe.
 */
enum QuoteCommentVisibility: string
{
    case Shared = 'shared';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Shared => 'Compartilhado',
            self::Internal => 'Nota interna',
        };
    }
}
