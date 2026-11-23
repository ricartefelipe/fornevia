<?php

declare(strict_types=1);

namespace App\Services\Quotes;

use App\Data\Inventory\ReservationRequest;
use App\Enums\Inventory\ReservationHolder;
use App\Enums\Quotes\StockHoldEndReason;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Quotes\QuoteItem;
use App\Models\Quotes\QuoteVersion;
use App\Services\Inventory\StockLedger;
use App\Support\Clock\Clock;
use Carbon\CarbonImmutable;

/**
 * Reserva de estoque com prazo de uma versão de cotação (RN-043): tudo ou
 * nada, sem encomenda, no ledger de estoque, com o item da cotação como
 * titular. A liberação encerra a reserva na versão uma única vez, com o
 * motivo. Locks na ordem da ADR-006: a união dos saldos (a reservar e a
 * liberar) é bloqueada de uma vez antes de liberar ou reservar. Exige a
 * cotação bloqueada e contexto de equipe ou de sistema.
 */
final readonly class QuoteStockHolds
{
    public function __construct(
        private StockLedger $stock,
        private Clock $clock,
    ) {}

    /**
     * Bloqueia os saldos para reservar a nova versão e liberar a reserva da
     * versão anterior, numa única consulta ordenada.
     */
    public function lockForSwap(?QuoteVersion $reserving, ?QuoteVersion $releasing): void
    {
        $skuIds = $reserving !== null ? self::items($reserving)->pluck('sku_id')->map(static fn (mixed $id): string => (string) $id)->all() : [];
        $holders = $releasing !== null && $releasing->stock_hold_status === StockHoldStatus::Active
            ? self::items($releasing)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all()
            : [];

        $this->stock->lockLevels(array_values($skuIds), ReservationHolder::QuoteItem, array_values($holders));
    }

    /**
     * Reserva toda a versão até o prazo; InsufficientStock desfaz tudo.
     */
    public function hold(QuoteVersion $version, CarbonImmutable $until): void
    {
        $items = self::items($version);

        $this->stock->reserve(array_values($items->map(static fn (QuoteItem $item): ReservationRequest => new ReservationRequest(
            $item->id,
            $item->sku_id,
            $item->sku_code,
            $item->base_quantity,
            false,
            ReservationHolder::QuoteItem,
            $until,
        ))->all()), 'quote_version', $version->id);
    }

    /**
     * Libera a reserva ativa da versão e registra o fim. Sem reserva ativa,
     * não faz nada.
     */
    public function release(QuoteVersion $version, StockHoldEndReason $reason, bool $locked = false): void
    {
        if ($version->stock_hold_status !== StockHoldStatus::Active) {
            return;
        }

        $itemIds = array_values(self::items($version)->pluck('id')->map(static fn (mixed $id): string => (string) $id)->all());

        if (! $locked) {
            $this->stock->lockLevels([], ReservationHolder::QuoteItem, $itemIds);
        }

        $this->stock->releaseForQuoteItems($itemIds, $reason->label(), 'quote_version', $version->id);

        $version->forceFill([
            'stock_hold_status' => StockHoldStatus::Ended,
            'stock_hold_ended_at' => $this->clock->now(),
            'stock_hold_end_reason' => $reason,
        ])->save();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, QuoteItem>
     */
    private static function items(QuoteVersion $version): \Illuminate\Database\Eloquent\Collection
    {
        return QuoteItem::query()
            ->where('quote_version_id', $version->id)
            ->where('buyer_company_id', $version->buyer_company_id)
            ->orderBy('line_number')
            ->get();
    }
}
