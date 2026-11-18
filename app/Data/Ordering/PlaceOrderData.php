<?php

declare(strict_types=1);

namespace App\Data\Ordering;

/**
 * Dados de checkout vindos do cliente. Nenhum preço ou total é aceito como
 * verdade: expectedTotalCents só serve para detectar mudança de valores
 * entre a exibição e a confirmação.
 */
final readonly class PlaceOrderData
{
    public function __construct(
        public string $cartId,
        public string $idempotencyKey,
        public string $shippingAddressId,
        public string $paymentTermId,
        public ?string $billingAddressId = null,
        public ?string $buyerUnitId = null,
        public ?string $costCenterId = null,
        public ?string $purchaseOrderReference = null,
        public ?string $notes = null,
        public ?int $expectedTotalCents = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'cart' => $this->cartId,
            'shipping' => $this->shippingAddressId,
            'billing' => $this->billingAddressId,
            'payment_term' => $this->paymentTermId,
            'unit' => $this->buyerUnitId,
            'cost_center' => $this->costCenterId,
            'po' => $this->purchaseOrderReference,
            'notes' => $this->notes,
            'expected_total' => $this->expectedTotalCents,
        ];
    }
}
