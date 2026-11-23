<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Enums\Ordering\OrderSource;
use App\Models\Ordering\Order;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteVersion;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Locks das cotações na ordem da ADR-006: cotação e, numa única consulta
 * ordenada por id, as versões dela. Operações que partem de um pedido
 * gerado de cotação bloqueiam o pedido e, logo depois, a cotação e a
 * versão de origem (lockSourceOf). Rodam no contexto de sistema ou de
 * equipe; a empresa é filtrada explicitamente quando informada.
 */
final class QuoteLocks
{
    public function lock(string $quoteId, ?string $buyerCompanyId = null): Quote
    {
        return Quote::query()
            ->when($buyerCompanyId !== null, static fn ($query) => $query->where('buyer_company_id', $buyerCompanyId))
            ->lockForUpdate()
            ->findOrFail($quoteId);
    }

    /**
     * @return Collection<int, QuoteVersion>
     */
    public function versions(Quote $quote): Collection
    {
        return QuoteVersion::query()
            ->where('quote_id', $quote->id)
            ->where('buyer_company_id', $quote->buyer_company_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * Cotação e versão de origem de um pedido já bloqueado (null para
     * pedidos que não vêm de cotação).
     *
     * @return array{0: Quote, 1: QuoteVersion}|null
     */
    public function lockSourceOf(Order $order): ?array
    {
        if ($order->source !== OrderSource::Quote || $order->source_quote_version_id === null) {
            return null;
        }

        $quoteId = QuoteVersion::query()
            ->where('buyer_company_id', $order->buyer_company_id)
            ->whereKey($order->source_quote_version_id)
            ->value('quote_id') ?? throw new LogicException('Versão de origem do pedido não encontrada.');

        $quote = $this->lock((string) $quoteId, $order->buyer_company_id);
        $version = $this->versions($quote)->firstWhere('id', $order->source_quote_version_id)
            ?? throw new LogicException('Versão de origem do pedido não encontrada.');

        return [$quote, $version];
    }
}
