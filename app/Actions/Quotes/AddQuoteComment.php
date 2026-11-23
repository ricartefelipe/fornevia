<?php

declare(strict_types=1);

namespace App\Actions\Quotes;

use App\Actions\Quotes\Portal\BuyerQuotes;
use App\Actions\Quotes\Staff\StaffQuoteQuery;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Enums\Identity\Permission;
use App\Enums\Quotes\QuoteCommentVisibility;
use App\Models\Identity\User;
use App\Models\Quotes\QuoteComment;
use App\Services\Audit\AuditRecorder;
use App\Services\Quotes\QuoteLocks;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * Comentário na cotação (RF-040): o comprador comenta na conversa
 * compartilhada; a equipe comenta na conversa ou em nota interna, que o
 * comprador não lê. Só em cotação não encerrada, sob o lock da cotação, e
 * até 30 por minuto por usuário. No portal, a gravação roda no contexto de
 * sistema do mesmo usuário (ADR-024).
 */
final readonly class AddQuoteComment
{
    public const int MAX_LENGTH = 2000;

    public const int PER_MINUTE = 30;

    public function __construct(
        private Access $access,
        private BuyerQuotes $buyerQuotes,
        private StaffQuoteQuery $staffQuotes,
        private QuoteLocks $locks,
        private TenantDatabase $db,
        private Outbox $outbox,
        private AuditRecorder $audit,
        private Clock $clock,
    ) {}

    public function forBuyer(mixed $quoteId, string $body): QuoteComment
    {
        $actor = $this->buyerQuotes->actor();
        $quote = $this->buyerQuotes->find($actor, $quoteId);
        $body = $this->body($body, $actor->membership->user_id);
        $name = User::query()->whereKey($actor->membership->user_id)->value('name') ?? 'Comprador';

        return DB::transaction(function () use ($quote, $body, $actor, $name): QuoteComment {
            $comment = $this->db->asSystem(fn (): QuoteComment => $this->record($quote->id, $actor->company->id, $body, $name, QuoteCommentVisibility::Shared, $actor->membership->id, null));
            $this->audit->record('quote.comment_added', $quote, ['visibility' => 'shared', 'buyer_company_id' => $quote->buyer_company_id]);

            return $comment;
        });
    }

    public function forStaff(mixed $quoteId, string $body, bool $internal): QuoteComment
    {
        $this->access->authorize(Permission::QuotesManage);
        $quote = $this->staffQuotes->find($quoteId);
        $membership = $this->access->membership();
        $body = $this->body($body, $membership->user_id);
        $name = User::query()->whereKey($membership->user_id)->value('name') ?? 'Equipe';
        $visibility = $internal ? QuoteCommentVisibility::Internal : QuoteCommentVisibility::Shared;

        return DB::transaction(function () use ($quote, $body, $membership, $name, $visibility): QuoteComment {
            $comment = $this->record($quote->id, $quote->buyer_company_id, $body, $name, $visibility, null, $membership->id);
            $this->audit->record('quote.comment_added', $quote, ['visibility' => $visibility->value, 'buyer_company_id' => $quote->buyer_company_id]);

            return $comment;
        });
    }

    private function record(string $quoteId, string $companyId, string $body, string $name, QuoteCommentVisibility $visibility, ?string $buyerMembershipId, ?string $staffMembershipId): QuoteComment
    {
        $quote = $this->locks->lock($quoteId, $companyId);

        if ($quote->status->isTerminal()) {
            throw new QuoteNotOpen('A cotação está encerrada e não recebe comentários.');
        }

        $now = $this->clock->now();
        $comment = new QuoteComment;
        $comment->forceFill([
            'buyer_company_id' => $quote->buyer_company_id,
            'quote_id' => $quote->id,
            'author_user_id' => $this->access->userId() ?? throw new LogicException('Comentário exige usuário.'),
            'buyer_membership_id' => $buyerMembershipId,
            'staff_membership_id' => $staffMembershipId,
            'author_name' => mb_substr($name, 0, 120),
            'visibility' => $visibility,
            'body' => $body,
            'created_at' => $now,
        ])->save();

        $quote->forceFill(['last_activity_at' => $now])->save();

        if ($visibility === QuoteCommentVisibility::Shared) {
            $this->outbox->record('quote.comment_added', 'quote', $quote->id, ['quote_id' => $quote->id, 'comment_id' => $comment->id, 'from_buyer' => $buyerMembershipId !== null]);
        }

        return $comment;
    }

    private function body(string $body, string $userId): string
    {
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw new InvalidQuote('Escreva um comentário de até '.number_format(self::MAX_LENGTH, 0, ',', '.').' caracteres.', ['field' => 'comment']);
        }

        $key = 'quote-comment:'.$userId;

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            throw new InvalidQuote('Muitos comentários em pouco tempo. Aguarde um minuto.', ['field' => 'comment']);
        }

        RateLimiter::hit($key, 60);

        return $body;
    }
}
