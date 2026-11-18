<?php

declare(strict_types=1);

namespace App\Enums\Ordering;

enum CommercialStatus: string
{
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Confirmed = 'confirmed';
    case PartiallyCancelled = 'partially_cancelled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PendingApproval => 'Aguardando aprovação interna',
            self::Approved => 'Aprovado internamente',
            self::Confirmed => 'Confirmado',
            self::PartiallyCancelled => 'Parcialmente cancelado',
            self::Cancelled => 'Cancelado',
            self::Completed => 'Concluído',
            self::Rejected => 'Reprovado',
        };
    }
}
