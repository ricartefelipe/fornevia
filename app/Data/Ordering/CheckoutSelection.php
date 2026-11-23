<?php

declare(strict_types=1);

namespace App\Data\Ordering;

use App\Models\Buyers\BuyerAddress;
use App\Models\Pricing\PaymentTerm;

/**
 * Escolhas do checkout já validadas para a empresa: endereços ativos,
 * condição de pagamento permitida e ativa, unidade e centro de custo.
 */
final readonly class CheckoutSelection
{
    public function __construct(
        public BuyerAddress $shipping,
        public ?BuyerAddress $billing,
        public PaymentTerm $paymentTerm,
        public ?string $buyerUnitId,
        public ?string $costCenterId,
    ) {}
}
