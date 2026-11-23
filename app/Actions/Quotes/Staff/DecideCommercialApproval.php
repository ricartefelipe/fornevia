<?php

declare(strict_types=1);

namespace App\Actions\Quotes\Staff;

use App\Data\Quotes\ReviewedQuoteVersion;
use App\Domain\Quotes\Exceptions\CommercialApprovalNotAllowed;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteExpired;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Models\Quotes\QuoteCommercialReview;
use App\Models\Quotes\QuoteVersion;
use App\Services\Audit\AuditRecorder;
use App\Services\Quotes\QuoteLocks;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

/**
 * Aprovação comercial de uma versão fora da política (RN-041), distinta da
 * aprovação interna do comprador. Decide quem tem pricing.approve, limite
 * de desconto maior ou igual ao maior desconto da versão e não é o autor
 * (o autor elegível se aprova na submissão). A decisão vale só para a
 * versão e a impressão exibidas, com a versão em aprovação e dentro da
 * validade; com "aprovar e enviar" (exige quotes.manage), a versão vai ao
 * comprador na mesma transação.
 */
final readonly class DecideCommercialApproval
{
    public function __construct(
        private Access $access,
        private StaffQuoteQuery $quotes,
        private QuoteLocks $locks,
        private SendQuoteVersion $send,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function handle(mixed $quoteId, ReviewedQuoteVersion $reviewed, bool $approve, ?string $comment = null, bool $sendNow = false): QuoteVersion
    {
        $this->access->authorize(Permission::PricingApprove);
        $quote = $this->quotes->find($quoteId);
        $membership = $this->access->membership();
        $limit = $this->access->maxDiscountBps();
        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        if (! $approve && mb_strlen((string) $comment) < 5) {
            throw new InvalidQuote('Informe o motivo da reprovação (ao menos 5 caracteres).', ['field' => 'comment']);
        }

        if ($comment !== null && mb_strlen($comment) > 1000) {
            throw new InvalidQuote('O comentário deve ter até 1.000 caracteres.', ['field' => 'comment']);
        }

        if ($sendNow && (! $approve || ! $this->access->can(Permission::QuotesManage))) {
            throw new CommercialApprovalNotAllowed('Aprovar e enviar exige permissão de elaborar propostas.');
        }

        return DB::transaction(function () use ($quote, $reviewed, $approve, $comment, $sendNow, $membership, $limit): QuoteVersion {
            $locked = $this->locks->lock($quote->id);
            $versions = $this->locks->versions($locked);
            $version = $versions->firstWhere('id', $reviewed->versionId);

            if ($version === null || $version->status !== QuoteVersionStatus::PendingApproval || ! $reviewed->matches($version)) {
                throw new QuoteVersionOutdated('A versão mudou ou já foi decidida desde que você abriu esta página. Recarregue antes de decidir.');
            }

            $review = QuoteCommercialReview::query()->where('quote_version_id', $version->id)->lockForUpdate()->firstOrFail();

            if ($review->decision !== null) {
                throw new QuoteVersionOutdated('Esta versão já foi decidida.');
            }

            if ($review->evaluated_by_membership_id === $membership->id) {
                throw new CommercialApprovalNotAllowed('Quem elaborou a versão não decide a própria exceção: a autoaprovação só é possível na submissão.');
            }

            if ($approve && $limit < $review->max_discount_bps) {
                throw new CommercialApprovalNotAllowed('Seu limite de desconto não cobre o maior desconto desta versão.');
            }

            $now = $this->clock->now();

            if ($version->valid_until->lessThanOrEqualTo($now)) {
                throw new QuoteExpired('A validade desta versão já terminou.');
            }

            $review->forceFill([
                'decision' => $approve ? 'approved' : 'rejected',
                'decided_by_user_id' => $membership->user_id,
                'decided_by_membership_id' => $membership->id,
                'decider_limit_bps' => $limit,
                'decision_comment' => $comment,
                'decided_at' => $now,
            ])->save();

            $version->forceFill($approve
                ? ['status' => QuoteVersionStatus::Ready]
                : ['status' => QuoteVersionStatus::ApprovalRejected, 'closed_at' => $now])->save();

            if ($sendNow) {
                $this->send->sendLocked($locked, $versions, $version, $membership);
            } else {
                $locked->forceFill(['last_activity_at' => $now])->save();
            }

            $this->outbox->record('quote.commercial_approval_decided', 'quote', $locked->id, ['quote_id' => $locked->id, 'version_id' => $version->id, 'approved' => $approve]);
            $this->audit->record('quote.commercial_approval_decided', $locked, [
                'version_id' => $version->id, 'approved' => $approve, 'sent' => $sendNow, 'max_discount_bps' => $review->max_discount_bps,
                'buyer_company_id' => $locked->buyer_company_id,
            ], $comment);

            return $version;
        });
    }
}
