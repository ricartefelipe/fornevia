<?php

declare(strict_types=1);

namespace App\Domain\Ordering\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados de entrega ou pagamento inválidos para esta empresa.
 */
final class InvalidCheckout extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_checkout';
    }
}
