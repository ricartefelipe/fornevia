<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * O representante responsável precisa ser uma associação de equipe ativa do distribuidor.
 */
final class InvalidSalesRepresentative extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_sales_representative';
    }
}
