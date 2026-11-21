<?php

declare(strict_types=1);

namespace App\Domain\Credit;

enum CreditDecision: string
{
    case Approved = 'approved';
    case Blocked = 'blocked';
    case Overdue = 'overdue';
    case Insufficient = 'insufficient';

    public function approved(): bool
    {
        return $this === self::Approved;
    }

    public function message(): string
    {
        return match ($this) {
            self::Approved => 'Crédito disponível.',
            self::Blocked => 'O crédito comercial da empresa está bloqueado.',
            self::Overdue => 'Há títulos vencidos acima da tolerância; regularize para comprar a prazo.',
            self::Insufficient => 'Limite de crédito disponível insuficiente para este pedido.',
        };
    }
}
