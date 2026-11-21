<?php

declare(strict_types=1);

namespace App\Domain\Buyers\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O grupo de clientes está em uso (empresas, tabelas de preço ou catálogos privados).
 */
final class CustomerGroupInUse extends ConflictingState
{
    public function errorCode(): string
    {
        return 'customer_group_in_use';
    }
}
