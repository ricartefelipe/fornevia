<?php

declare(strict_types=1);

namespace App\Actions\Quotes\System;

use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteVersion;
use App\Services\Quotes\QuoteLocks;
use App\Services\Quotes\QuoteStockHolds;
use App\Support\Clock\Clock;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Expiração agendada (ADR-025): registra o vencimento das propostas e
 * libera reservas com prazo vencido, inclusive as de versões aceitas cujo
 * pedido aguarda aprovação. Toda ação confere a validade sob lock por
 * conta própria; o job só registra e libera.
 *
 * Por tenant, no contexto de sistema, até um lote de cotações por rodada;
 * cada cotação em transação própria, com o lock tomado com SKIP LOCKED (a
 * ocupada fica para a próxima rodada). Nunca bloqueia pedidos. Falha em um
 * tenant é registrada e não interrompe os demais; rodar de novo não tem
 * efeito.
 */
final readonly class ExpireDueQuotes
{
    public function __construct(
        private TenantDatabase $db,
        private QuoteLocks $locks,
        private QuoteStockHolds $holds,
        private Outbox $outbox,
        private Clock $clock,
    ) {}

    /**
     * @return array{expired: int, holds_released: int, skipped: int}
     */
    public function handle(): array
    {
        $totals = ['expired' => 0, 'holds_released' => 0, 'skipped' => 0];

        foreach (DB::table('organizations')->orderBy('id')->pluck('id') as $tenantId) {
            try {
                foreach ($this->dueQuotes((string) $tenantId) as $quoteId) {
                    $result = $this->db->run(ExecutionContext::system((string) $tenantId), fn (): array => $this->expire($quoteId));

                    foreach ($result as $key => $count) {
                        $totals[$key] += $count;
                    }
                }
            } catch (Throwable $e) {
                Log::error('Falha ao expirar cotações do tenant.', ['tenant_id' => (string) $tenantId, 'exception' => $e]);
            }
        }

        return $totals;
    }

    /**
     * @return list<string>
     */
    private function dueQuotes(string $tenantId): array
    {
        $now = $this->clock->now();

        return array_values($this->db->run(ExecutionContext::system($tenantId), static fn (): array => QuoteVersion::query()
            ->where(static fn ($due) => $due
                ->where(static fn ($validity) => $validity
                    ->whereIn('status', [QuoteVersionStatus::PendingApproval->value, QuoteVersionStatus::Ready->value, QuoteVersionStatus::Sent->value])
                    ->where('valid_until', '<=', $now))
                ->orWhere(static fn ($hold) => $hold
                    ->where('stock_hold_status', StockHoldStatus::Active->value)
                    ->where('stock_hold_until', '<=', $now)))
            ->orderBy('quote_id')
            ->distinct()
            ->limit((int) config('quotes.expire_batch', 100))
            ->pluck('quote_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all()));
    }

    /**
     * @return array{expired: int, holds_released: int, skipped: int}
     */
    private function expire(string $quoteId): array
    {
        $result = ['expired' => 0, 'holds_released' => 0, 'skipped' => 0];
        $quote = Quote::query()->whereKey($quoteId)->lock('for update skip locked')->first();

        if ($quote === null) {
            return [...$result, 'skipped' => 1];
        }

        $now = $this->clock->now();

        foreach ($this->locks->versions($quote) as $version) {
            $validityDue = $version->status->isOpen() && $version->valid_until->lessThanOrEqualTo($now);
            $holdDue = $version->stock_hold_status === StockHoldStatus::Active
                && ($validityDue || ($version->stock_hold_until !== null && $version->stock_hold_until->lessThanOrEqualTo($now)));

            if ($holdDue) {
                $this->holds->release($version, StockHoldEndReason::Expired);
                $result['holds_released']++;
                $this->outbox->record('quote.stock_hold_expired', 'quote', $quote->id, ['quote_id' => $quote->id, 'version_id' => $version->id]);
            }

            if (! $validityDue) {
                continue;
            }

            $wasSent = $version->status === QuoteVersionStatus::Sent;
            $version->forceFill(['status' => QuoteVersionStatus::Expired, 'closed_at' => $now])->save();
            $result['expired']++;

            if ($wasSent && $quote->status === QuoteStatus::Proposed) {
                $quote->forceFill(['status' => QuoteStatus::Expired, 'last_activity_at' => $now])->save();
                $this->outbox->record('quote.expired', 'quote', $quote->id, ['quote_id' => $quote->id, 'version_id' => $version->id]);
            }
        }

        return $result;
    }
}
