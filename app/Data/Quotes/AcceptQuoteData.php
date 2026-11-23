<?php

declare(strict_types=1);

namespace App\Data\Quotes;

/**
 * Aceite da proposta exibida, com as escolhas do pedido que a cotação não
 * define (endereços, unidade, centro de custo, ordem de compra,
 * observações). Sem preço, total nem condição de pagamento: vêm da versão.
 */
final readonly class AcceptQuoteData
{
    public function __construct(
        public ReviewedQuoteVersion $reviewed,
        public string $idempotencyKey,
        public string $shippingAddressId,
        public ?string $billingAddressId = null,
        public ?string $buyerUnitId = null,
        public ?string $costCenterId = null,
        public ?string $purchaseOrderReference = null,
        public ?string $notes = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'version' => $this->reviewed->versionId,
            'fingerprint' => $this->reviewed->fingerprint,
            'hold' => $this->reviewed->holdActive,
            'shipping' => $this->shippingAddressId,
            'billing' => $this->billingAddressId,
            'unit' => $this->buyerUnitId,
            'cost_center' => $this->costCenterId,
            'po' => $this->purchaseOrderReference,
            'notes' => $this->notes,
        ];
    }
}
