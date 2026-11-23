<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Staff;

use App\Data\Quotes\ReviewedQuoteState;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Models\Quotes\QuoteVersion;
use App\Services\Audit\AuditRecorder;
use App\Services\Quotes\QuoteLocks;
use App\Services\Quotes\QuoteStockHolds;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Retirada de uma versão pela equipe, com motivo: em preparo, ela deixa de
 * valer; com o comprador, deixa de poder ser aceita, a reserva é encerrada
 * e a cotação volta a aguardar proposta.
 */
final readonly class WithdrawQuoteVersion
{
    public function __construct(
        private Access $access,
        private StaffQuoteQuery $quotes,
        private QuoteLocks $locks,
        private QuoteStockHolds $holds,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $quoteId, ReviewedQuoteState $reviewed, mixed $versionId, string $reason): QuoteVersion
    {
        $this->access->authorize(Permission::QuotesManage);
        $quote = $this->quotes->find($quoteId);
        $versionId = UntrustedId::uuid($versionId, QuoteVersion::class);
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw new InvalidQuote('Informe o motivo da retirada (de 5 a 500 caracteres).', ['field' => 'reason']);
        }

        return DB::transaction(function () use ($quote, $reviewed, $versionId, $reason): QuoteVersion {
            $locked = $this->locks->lock($quote->id);
            $versions = $this->locks->versions($locked);
            $version = $versions->firstWhere('id', $versionId);

            if ($version === null || ! $version->status->isOpen() || ! ReviewedQuoteState::of($versions)->equals($reviewed)) {
                throw new QuoteVersionOutdated('A versão mudou desde que você abriu esta página. Recarregue antes de retirar.');
            }

            $now = $this->clock->now();
            $wasSent = $version->status === QuoteVersionStatus::Sent;
            $this->holds->release($version, StockHoldEndReason::Withdrawn);
            $version->forceFill(['status' => QuoteVersionStatus::Withdrawn, 'closed_at' => $now])->save();

            if ($wasSent) {
                $locked->forceFill(['status' => QuoteStatus::Requested]);
                $this->outbox->record('quote.proposal_withdrawn', 'quote', $locked->id, ['quote_id' => $locked->id, 'version_id' => $version->id]);
            }

            $locked->forceFill(['last_activity_at' => $now])->save();
            $this->audit->record('quote.version_withdrawn', $locked, ['version_id' => $version->id, 'was_sent' => $wasSent, 'buyer_company_id' => $locked->buyer_company_id], $reason);

            return $version;
        });
    }
}
