<?php

declare(strict_types=1);

namespace App\Data\Quotes;

use App\Enums\Quotes\StockHoldStatus;
use App\Models\Quotes\QuoteVersion;

/**
 * Versão exibida a quem decide (aprovador comercial ou comprador): id,
 * impressão do conteúdo e, para o comprador, se a reserva de estoque
 * estava ativa. A decisão só vale se, sob o lock da cotação, a versão ainda
 * for essa (RN-040).
 */
final readonly class ReviewedQuoteVersion
{
    public function __construct(
        public string $versionId,
        public string $fingerprint,
        public ?bool $holdActive = null,
    ) {}

    public static function of(QuoteVersion $version, bool $withHold = false): self
    {
        return new self($version->id, $version->content_fingerprint, $withHold ? $version->stock_hold_status === StockHoldStatus::Active : null);
    }

    public function matches(QuoteVersion $version): bool
    {
        return $this->versionId === $version->id
            && hash_equals($version->content_fingerprint, $this->fingerprint)
            && ($this->holdActive === null || $this->holdActive === ($version->stock_hold_status === StockHoldStatus::Active));
    }
}
