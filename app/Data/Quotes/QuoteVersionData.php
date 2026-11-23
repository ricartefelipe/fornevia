<?php

declare(strict_types=1);

namespace App\Data\Quotes;

/**
 * Nova versão da proposta elaborada pela equipe: linhas (SKU, quantidade e
 * desconto; nunca preço), condição de pagamento, validade, reserva
 * opcional, observações e, com exceção à política, justificativa e pedido
 * de autoaprovação. O total esperado é o da prévia exibida.
 */
final readonly class QuoteVersionData
{
    /**
     * @param  list<QuoteLineInput>  $lines
     */
    public function __construct(
        public string $quoteId,
        public ReviewedQuoteState $reviewed,
        public string $idempotencyKey,
        public array $lines,
        public string $paymentTermId,
        public string $validUntilDay,
        public ?string $stockHoldUntilDay = null,
        public ?string $notes = null,
        public ?string $justification = null,
        public bool $selfApprove = false,
        public ?int $expectedTotalCents = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function fingerprintPayload(): array
    {
        return [
            'quote' => $this->quoteId,
            'reviewed' => [$this->reviewed->withBuyerVersionId, $this->reviewed->preparingVersionId],
            'lines' => array_map(static fn (QuoteLineInput $line): array => [$line->skuId, $line->quantity->toDatabase(), $line->discount->basisPoints], $this->lines),
            'term' => $this->paymentTermId,
            'valid' => $this->validUntilDay,
            'hold' => $this->stockHoldUntilDay,
            'notes' => $this->notes,
            'justification' => $this->justification,
            'self' => $this->selfApprove,
            'total' => $this->expectedTotalCents,
        ];
    }
}
