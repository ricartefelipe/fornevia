<?php

declare(strict_types=1);

namespace App\Enums\Approvals;

/**
 * Tipo de movimento de orçamento.
 */
enum BudgetMovementType: string
{
    case Reservation = 'reservation';
    case Release = 'release';
    case Consumption = 'consumption';

    public function label(): string
    {
        return match ($this) {
            self::Reservation => 'Reserva',
            self::Release => 'Liberação',
            self::Consumption => 'Consumo',
        };
    }
}
