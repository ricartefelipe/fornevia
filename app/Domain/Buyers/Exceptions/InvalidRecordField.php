<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Campo obrigatório ausente, longo demais ou em formato inválido.
 */
final class InvalidRecordField extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'invalid_record_field';
    }
}
