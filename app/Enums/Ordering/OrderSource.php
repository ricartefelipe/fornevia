<?php

declare(strict_types=1);

namespace App\Enums\Ordering;

enum OrderSource: string
{
    case Cart = 'cart';
    case Reorder = 'reorder';
    case Quote = 'quote';
    case Api = 'api';
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Cart => 'Carrinho',
            self::Reorder => 'Recompra',
            self::Quote => 'Cotação',
            self::Api => 'Integração',
            self::Staff => 'Equipe',
        };
    }
}
