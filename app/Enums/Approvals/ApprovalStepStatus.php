<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

/**
 * Situação de um nível de aprovação do pedido.
 */
enum ApprovalStepStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendente',
            self::Approved => 'Aprovado',
            self::Rejected => 'Rejeitado',
        };
    }
}
