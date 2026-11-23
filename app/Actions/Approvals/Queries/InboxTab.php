<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

/**
 * Abas da fila de aprovação. Os valores aparecem na URL (?aba=).
 */
enum InboxTab: string
{
    case Awaiting = 'pendentes';
    case Decided = 'decididos';
    case Blocked = 'bloqueados';

    public function label(): string
    {
        return match ($this) {
            self::Awaiting => 'Aguardando sua decisão',
            self::Decided => 'Decididos por você',
            self::Blocked => 'Bloqueados',
        };
    }
}
