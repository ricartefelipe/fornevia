<?php

declare(strict_types=1);

namespace Tests\Integration\Approvals\Inbox;

use App\Actions\Approvals\Queries\ApprovalInbox;
use App\Actions\Approvals\Queries\ApprovalReview;
use App\Actions\Approvals\Queries\ApprovalReviewData;
use App\Actions\Approvals\Queries\InboxEntry;
use App\Actions\Approvals\Queries\InboxOverview;
use App\Actions\Approvals\Queries\InboxTab;
use App\Enums\Identity\MembershipStatus;
use App\Models\Approvals\ApprovalDelegation;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\Builders\OrderingScenario;

/**
 * Apoio aos testes da fila de aprovação: delegações, revogações e datas de
 * submissão gravadas no contexto de sistema; consultas executadas como a
 * associação informada.
 */
final class InboxFixtures
{
    public static function delegate(OrderingScenario $scenario, Membership $from, Membership $to, string $validFrom = '-1 day', string $validUntil = '+5 days'): ApprovalDelegation
    {
        return $scenario->system(static function () use ($from, $to, $validFrom, $validUntil): ApprovalDelegation {
            $delegation = new ApprovalDelegation;
            $delegation->forceFill([
                'buyer_company_id' => $from->buyer_company_id,
                'delegator_membership_id' => $from->id,
                'delegate_membership_id' => $to->id,
                'valid_from' => CarbonImmutable::parse($validFrom),
                'valid_until' => CarbonImmutable::parse($validUntil),
                'reason' => 'Férias da titular',
                'created_by_user_id' => $from->user_id,
            ])->save();

            return $delegation;
        });
    }

    public static function revoke(OrderingScenario $scenario, Membership $membership): void
    {
        $scenario->system(static fn () => Membership::query()->whereKey($membership->id)->update([
            'status' => MembershipStatus::Revoked->value,
            'revoked_at' => now(),
        ]));
    }

    public static function submittedAt(OrderingScenario $scenario, Order $order, string $when): void
    {
        $scenario->system(static fn () => DB::table('orders')->where('id', $order->id)->update(['submitted_at' => CarbonImmutable::parse($when)]));
    }

    public static function overview(OrderingScenario $scenario, Membership $membership, InboxTab $tab = InboxTab::Awaiting, int $page = 1, int $perPage = ApprovalInbox::PER_PAGE): InboxOverview
    {
        return $scenario->tenant->actingAs($membership, static function () use ($tab, $page, $perPage): InboxOverview {
            $inbox = app(ApprovalInbox::class);

            return $inbox->overview($inbox->actor(), $tab, $page, $perPage);
        });
    }

    /**
     * Números dos pedidos da aba, na ordem exibida.
     *
     * @return list<string>
     */
    public static function numbers(OrderingScenario $scenario, Membership $membership, InboxTab $tab = InboxTab::Awaiting): array
    {
        return array_map(
            static fn (InboxEntry $entry): string => $entry->order->number,
            array_values(self::overview($scenario, $membership, $tab)->entries->items()),
        );
    }

    public static function review(OrderingScenario $scenario, Membership $membership, string $orderId): ApprovalReviewData
    {
        return $scenario->tenant->actingAs($membership, static function () use ($orderId): ApprovalReviewData {
            $review = app(ApprovalReview::class);
            $actor = $review->actor();

            return $review->review($actor, $review->find($actor, $orderId));
        });
    }

    public static function name(Membership $membership): string
    {
        return User::query()->findOrFail($membership->user_id)->name;
    }
}
