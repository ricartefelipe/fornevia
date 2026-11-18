<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Já existe empresa compradora com este CNPJ no distribuidor.
 */
final class BuyerCompanyAlreadyRegistered extends ConflictingState
{
    public function errorCode(): string
    {
        return 'buyer_company_already_registered';
    }
}
