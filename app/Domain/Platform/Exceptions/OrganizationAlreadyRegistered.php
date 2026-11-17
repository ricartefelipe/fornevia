<?php

declare(strict_types=1);

namespace App\Domain\Platform\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * Já existe uma organização com este CNPJ.
 */
final class OrganizationAlreadyRegistered extends ConflictingState
{
    public function errorCode(): string
    {
        return 'organization_already_registered';
    }
}
