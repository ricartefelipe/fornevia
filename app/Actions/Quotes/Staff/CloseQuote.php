<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Staff;

use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteCloseReason;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Models\Quotes\Quote;
use App\Services\Audit\AuditRecorder;
use App\Services\Quotes\QuoteClosing;
use App\Services\Quotes\QuoteLocks;
use App\Support\Auth\Access;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Encerramento da cotação pela equipe, com motivo, em qualquer situação não
 * final: versões abertas são retiradas e reservas liberadas.
 */
final readonly class CloseQuote
{
    public function __construct(
        private Access $access,
        private StaffQuoteQuery $quotes,
        private QuoteLocks $locks,
        private QuoteClosing $closing,
        private Outbox $outbox,
        private AuditRecorder $audit,
    ) {}

    public function handle(mixed $quoteId, string $reason): Quote
    {
        $this->access->authorize(Permission::QuotesManage);
        $quote = $this->quotes->find($quoteId);
        [$code, $text] = QuoteClosing::reason(QuoteCloseReason::StaffClosed->value, $reason, staff: true);

        return DB::transaction(function () use ($quote, $code, $text): Quote {
            $locked = $this->locks->lock($quote->id);
            $versions = $this->locks->versions($locked);

            if ($locked->status->isTerminal()) {
                throw new QuoteNotOpen('A cotação já está encerrada.');
            }

            $this->closing->close($locked, $versions, QuoteStatus::Cancelled, QuoteVersionStatus::Withdrawn, StockHoldEndReason::Cancelled, $code, $text, $this->access->userId());
            $this->outbox->record('quote.cancelled', 'quote', $locked->id, ['quote_id' => $locked->id, 'by' => 'staff']);
            $this->audit->record('quote.closed', $locked, ['buyer_company_id' => $locked->buyer_company_id], $text);

            return $locked;
        });
    }
}
