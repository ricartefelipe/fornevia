<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Domain\Shared\Money;

/**
 * Situação de uma cobrança no gateway.
 */
final readonly class ChargeResult
{
    public const string PENDING = 'pending';

    public const string CONFIRMED = 'confirmed';

    public const string FAILED = 'failed';

    public function __construct(
        public string $chargeId,
        public string $status,
        public ?string $checkoutUrl = null,
        public ?Money $paidAmount = null,
        public ?Money $fee = null,
    ) {}
}
