<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

/**
 * Decisão de um aprovador.
 */
enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Aprovado',
            self::Rejected => 'Rejeitado',
        };
    }
}
