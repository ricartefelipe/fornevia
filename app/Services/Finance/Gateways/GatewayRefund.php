<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Enums\Finance\RefundOutcome;

/**
 * Reembolso consultado no gateway: situação e identificador do estorno
 * (quando encontrado).
 */
final readonly class GatewayRefund
{
    public function __construct(public RefundOutcome $outcome, public ?string $id = null) {}
}
