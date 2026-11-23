<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Portal;

use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
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
 * Recusa da proposta exibida pelo comprador, com motivo (RF-040): a versão
 * é recusada, a reserva liberada e a cotação encerrada.
 */
final readonly class RejectQuote
{
    public function __construct(
        private BuyerQuotes $quotes,
        private QuoteLocks $locks,
        private QuoteClosing $closing,
        private TenantDatabase $db,
        private Outbox $outbox,
        private AuditRecorder $audit,
    ) {}

    public function handle(mixed $quoteId, ReviewedQuoteVersion $reviewed, string $reasonCode, ?string $reason = null): Quote
    {
        $actor = $this->quotes->actor();
        $quote = $this->quotes->find($actor, $quoteId);
        [$code, $text] = QuoteClosing::reason($reasonCode, $reason);

        return DB::transaction(function () use ($actor, $quote, $reviewed, $code, $text): Quote {
            $locked = $this->db->asSystem(function () use ($actor, $quote, $reviewed, $code, $text): Quote {
                $locked = $this->locks->lock($quote->id, $actor->company->id);
                $versions = $this->locks->versions($locked);
                $version = $versions->firstWhere('id', $reviewed->versionId);

                if ($locked->status !== QuoteStatus::Proposed) {
                    throw new QuoteNotOpen('A cotação não está aguardando sua resposta.');
                }

                if ($version === null || $version->status !== QuoteVersionStatus::Sent || ! $reviewed->matches($version)) {
                    throw new QuoteVersionOutdated('A proposta mudou desde que você abriu esta página. Revise a versão atual.');
                }

                $this->closing->close($locked, $versions, QuoteStatus::Rejected, QuoteVersionStatus::Rejected, StockHoldEndReason::Rejected, $code, $text, $actor->membership->user_id);
                $this->outbox->record('quote.rejected', 'quote', $locked->id, ['quote_id' => $locked->id, 'version_id' => $version->id, 'reason' => $code->value]);

                return $locked;
            });

            $this->audit->record('quote.rejected', $locked, ['version_id' => $reviewed->versionId, 'reason' => $code->value, 'buyer_company_id' => $locked->buyer_company_id], $text);

            return $locked;
        });
    }
}
