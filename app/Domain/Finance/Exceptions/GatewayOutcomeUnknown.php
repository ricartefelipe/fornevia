<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O gateway não confirmou nem recusou a operação (tempo esgotado): ela pode
 * ter acontecido. Nada é repetido até a conferência.
 */
final class GatewayOutcomeUnknown extends ConflictingState
{
    public function errorCode(): string
    {
        return 'gateway_outcome_unknown';
    }
}
