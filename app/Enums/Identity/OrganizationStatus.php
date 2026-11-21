<?php

declare(strict_types=1);

namespace App\Enums\Identity;

/**
 * Situação do distribuidor perante a plataforma (assinatura do Fornevia).
 * Não se confunde com a situação comercial dos clientes do distribuidor.
 */
enum OrganizationStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function allowsAccess(): bool
    {
        return match ($this) {
            self::Trial, self::Active, self::PastDue => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Período de avaliação',
            self::Active => 'Ativa',
            self::PastDue => 'Pagamento pendente',
            self::Suspended => 'Suspensa',
            self::Cancelled => 'Cancelada',
        };
    }
}
