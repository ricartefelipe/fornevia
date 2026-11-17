<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Origem de um preço, em ordem de precedência (ADR-011).
 */
enum PriceSource: string
{
    case Negotiated = 'negotiated';
    case CustomerList = 'customer_list';
    case GroupList = 'group_list';
    case DefaultList = 'default_list';

    public function rank(): int
    {
        return match ($this) {
            self::Negotiated => 1,
            self::CustomerList => 2,
            self::GroupList => 3,
            self::DefaultList => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Negotiated => 'Condição negociada',
            self::CustomerList => 'Tabela do cliente',
            self::GroupList => 'Tabela do grupo',
            self::DefaultList => 'Tabela padrão',
        };
    }
}
