<?php

declare(strict_types=1);

namespace App\Enums\Ordering;

enum ApprovalStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Dispensada',
            self::Pending => 'Pendente',
            self::Approved => 'Aprovada',
            self::Rejected => 'Reprovada',
        };
    }
}
