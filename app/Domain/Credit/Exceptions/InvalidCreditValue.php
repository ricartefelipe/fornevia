<?php

declare(strict_types=1);

namespace App\Domain\Credit\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Valor de crédito fora das regras (limite negativo, tolerância fora do
 * intervalo, exceção sem valor ou com vigência inválida).
 */
final class InvalidCreditValue extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_credit_value';
    }
}
