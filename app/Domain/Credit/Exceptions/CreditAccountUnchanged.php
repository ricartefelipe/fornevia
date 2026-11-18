<?php

declare(strict_types=1);

namespace App\Domain\Credit\Exceptions;

use App\Domain\Shared\BusinessRuleViolation;

/**
 * Alteração sem efeito (mesmo limite, mesma situação ou mesma tolerância):
 * recusada para não registrar histórico e auditoria vazios.
 */
final class CreditAccountUnchanged extends BusinessRuleViolation
{
    public function errorCode(): string
    {
        return 'credit_account_unchanged';
    }
}
