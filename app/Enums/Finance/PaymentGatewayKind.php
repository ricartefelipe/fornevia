<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PaymentGatewayKind: string
{
    case Simulator = 'simulator';
    case Asaas = 'asaas';

    public function label(): string
    {
        return match ($this) {
            self::Simulator => 'Simulador local',
            self::Asaas => 'Asaas',
        };
    }
}
