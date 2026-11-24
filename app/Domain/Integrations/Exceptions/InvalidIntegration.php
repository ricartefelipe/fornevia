<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Dados de integração inválidos (cliente de API, webhook ou importação).
 */
final class InvalidIntegration extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_integration';
    }
}
