<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Portal;

use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Models\Quotes\Quote;
use App\Services\Audit\AuditRecorder;
use App\Services\Quotes\QuoteClosing;
use App\Services\Quotes\QuoteLocks;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Cancelamento da solicitação pelo comprador enquanto aguarda proposta ou
 * depois do vencimento. Com proposta vigente, o caminho é recusar.
 */
final readonly class CancelQuoteRequest
{
    public function __construct(
        private BuyerQuotes $quotes,
        private QuoteLocks $locks,
        private QuoteClosing $closing,
        private TenantDatabase $db,
        private Outbox $outbox,
        private AuditRecorder $audit,
    ) {}

    public function handle(mixed $quoteId, string $reasonCode, ?string $reason = null): Quote
    {
        $actor = $this->quotes->actor();
        $quote = $this->quotes->find($actor, $quoteId);
        [$code, $text] = QuoteClosing::reason($reasonCode, $reason);

        return DB::transaction(function () use ($actor, $quote, $code, $text): Quote {
            $locked = $this->db->asSystem(function () use ($actor, $quote, $code, $text): Quote {
                $locked = $this->locks->lock($quote->id, $actor->company->id);
                $versions = $this->locks->versions($locked);

                if (! in_array($locked->status, [QuoteStatus::Requested, QuoteStatus::Expired], true)) {
                    throw new QuoteNotOpen($locked->status === QuoteStatus::Proposed
                        ? 'Há uma proposta aguardando sua resposta: recuse-a para encerrar a cotação.'
                        : 'A cotação já está encerrada.');
                }

                $this->closing->close($locked, $versions, QuoteStatus::Cancelled, QuoteVersionStatus::Withdrawn, StockHoldEndReason::Cancelled, $code, $text, $actor->membership->user_id);
                $this->outbox->record('quote.cancelled', 'quote', $locked->id, ['quote_id' => $locked->id, 'by' => 'buyer']);

                return $locked;
            });

            $this->audit->record('quote.cancelled', $locked, ['reason' => $code->value, 'buyer_company_id' => $locked->buyer_company_id], $text);

            return $locked;
        });
    }
}
