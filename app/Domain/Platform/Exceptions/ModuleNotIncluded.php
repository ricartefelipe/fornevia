<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Módulo não incluído no plano contratado.
 */
final class ModuleNotIncluded extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'module_not_included';
    }
}
