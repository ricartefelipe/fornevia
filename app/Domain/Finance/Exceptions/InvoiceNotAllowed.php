<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Não há o que faturar ou a fatura não permite a operação.
 */
final class InvoiceNotAllowed extends ConflictingState
{
    public function errorCode(): string
    {
        return 'invoice_not_allowed';
    }
}
