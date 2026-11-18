<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * A empresa compradora já está na situação solicitada.
 */
final class BuyerCompanyStatusUnchanged extends ConflictingState
{
    public function errorCode(): string
    {
        return 'buyer_company_status_unchanged';
    }
}
