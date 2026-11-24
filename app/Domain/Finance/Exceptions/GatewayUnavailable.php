<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exceptions;

use App\Domain\Shared\ConflictingState;

/**
 * O gateway de pagamento não respondeu; nenhuma cobrança nova foi criada sem conferência.
 */
final class GatewayUnavailable extends ConflictingState
{
    public function errorCode(): string
    {
        return 'gateway_unavailable';
    }
}
