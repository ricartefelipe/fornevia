<?php

declare(strict_types=1);

namespace App\Enums\Fulfillment;

enum ReturnReason: string
{
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case NotOrdered = 'not_ordered';
    case Regret = 'regret';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Damaged => 'Produto avariado',
            self::WrongItem => 'Produto diferente do pedido',
            self::NotOrdered => 'Quantidade acima do pedido',
            self::Regret => 'Desistência',
            self::Other => 'Outro motivo',
        };
    }
}
