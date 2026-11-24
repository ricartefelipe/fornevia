<?php

declare(strict_types=1);

namespace App\Enums\Finance;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case Boleto = 'boleto';
    case Card = 'card';
    case Transfer = 'transfer';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::Pix => 'Pix',
            self::Boleto => 'Boleto',
            self::Card => 'Cartão (na página do gateway)',
            self::Transfer => 'Transferência',
            self::Cash => 'Dinheiro',
        };
    }
}
