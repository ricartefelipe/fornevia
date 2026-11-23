<?php

declare(strict_types=1);

namespace App\Data\Quotes;

/**
 * Solicitação de cotação a partir do carrinho: sem preço; os itens e as
 * quantidades vêm do carrinho, conferidos no servidor.
 */
final readonly class RequestQuoteData
{
    public function __construct(
        public string $cartId,
        public string $idempotencyKey,
        public ?string $message = null,
        public ?string $neededBy = null,
        public bool $removeFromCart = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return ['cart' => $this->cartId, 'message' => $this->message, 'needed_by' => $this->neededBy, 'remove' => $this->removeFromCart];
    }
}
