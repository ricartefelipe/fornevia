<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados do cabeçalho da tabela de preço inválidos (código, nome ou prioridade). O campo afetado vem em details['field'].
 */
final class InvalidPriceList extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_price_list';
    }
}
