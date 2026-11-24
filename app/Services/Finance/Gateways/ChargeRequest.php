<?php

declare(strict_types=1);

namespace App\Services\Finance\Gateways;

use App\Domain\Shared\Money;
use App\Enums\Finance\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * Cobrança pedida ao gateway. A referência externa é o id do pagamento:
 * é por ela que a cobrança já criada é encontrada depois de uma falha de
 * comunicação, sem criar outra (RF-101).
 */
final readonly class ChargeRequest
{
    public function __construct(
        public string $reference,
        public Money $amount,
        public PaymentMethod $method,
        public CarbonImmutable $dueDate,
        public string $description,
        public string $payerName,
        public string $payerDocument,
        public ?string $payerEmail,
    ) {}
}
