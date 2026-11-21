<?php

declare(strict_types=1);

namespace App\Actions\Approvals\Queries;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\BuyerActor;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Buyers\CostCenter;
use App\Models\Ordering\Order;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Fila de aprovação do comprador (RF-050), com portal.approvals:
 *
 * - aguardando: pedidos da empresa em que o ator pode decidir agora o nível
 *   pendente, como aprovador elegível ou delegado vigente de um (RN-053);
 * - decididos: pedidos com decisão do ator ou tomada em nome dele;
 * - bloqueados (portal.approval_rules): nível pendente sem aprovadores
 *   elegíveis suficientes para o quórum (RN-052).
 *
 * Pendentes em ordem de submissão (o mais antigo primeiro); decididos pela
 * decisão mais recente. A RLS restringe à empresa do contexto; o filtro é
 * repetido na consulta.
 */
final readonly class ApprovalInbox
{
    public const int PER_PAGE = 20;

    public function __construct(
        private PortalBuyer $buyer,
        private PendingApprovals $pending,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function actor(): BuyerActor
    {
        return $this->buyer->require(Permission::PortalApprovals);
    }

    public function seesBlocked(): bool
    {
        return $this->buyer->can(Permission::PortalApprovalRules);
    }

    /**
     * @throws AuthorizationException aba de bloqueados sem portal.approval_rules
     */
    public function overview(BuyerActor $actor, InboxTab $tab, int $page = 1, int $perPage = self::PER_PAGE): InboxOverview
    {
        $seesBlocked = $this->seesBlocked();

        if ($tab === InboxTab::Blocked && ! $seesBlocked) {
            $this->buyer->require(Permission::PortalApprovalRules);
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);

        // Quem vê os bloqueados avalia todos os pendentes da empresa de uma
        // vez; os demais, apenas os pedidos em que aparecem como aprovadores.
        $orders = ($seesBlocked ? $this->pending->orders($actor) : $this->pending->candidates($actor))
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get();
        $states = $this->pending->evaluate($actor, $orders);

        $awaiting = [];
        $blocked = [];

        foreach ($orders as $order) {
            $state = $states[$order->id] ?? null;

            if ($state === null) {
                continue;
            }

            if ($state->canDecide()) {
                $awaiting[] = [$order, $state];
            }

            if ($seesBlocked && $state->blocked) {
                $blocked[] = [$order, $state];
            }
        }

        $decided = $this->decidedQuery($actor);
        $counts = [InboxTab::Awaiting->value => count($awaiting), InboxTab::Decided->value => (clone $decided)->count()];

        if ($seesBlocked) {
            $counts[InboxTab::Blocked->value] = count($blocked);
        }

        return match ($tab) {
            InboxTab::Awaiting => new InboxOverview($tab, $this->pendingPage($actor, $awaiting, $page, $perPage), self::amount($awaiting), $counts),
            InboxTab::Blocked => new InboxOverview($tab, $this->pendingPage($actor, $blocked, $page, $perPage), self::amount($blocked), $counts),
            InboxTab::Decided => new InboxOverview($tab, $this->decidedPage($actor, $decided, $page, $perPage), null, $counts),
        };
    }

    /**
     * @param  list<array{0: Order, 1: PendingStepState}>  $rows
     * @return LengthAwarePaginator<int, InboxEntry>
     */
    private function pendingPage(BuyerActor $actor, array $rows, int $page, int $perPage): LengthAwarePaginator
    {
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $orders = array_map(static fn (array $row): Order => $row[0], $slice);
        $actorId = $actor->membership->id;
        $principals = MemberNames::ofMemberships(array_map(static fn (array $row): ?string => $row[1]->delegatedFrom($actorId), $slice));
        [$requesters, $centers, $levels] = $this->details($orders);

        $entries = array_map(static fn (array $row): InboxEntry => new InboxEntry(
            $row[0],
            $requesters[$row[0]->placed_by_user_id] ?? null,
            $row[0]->cost_center_id !== null ? ($centers[$row[0]->cost_center_id] ?? null) : null,
            $levels[$row[0]->id] ?? 0,
            $row[1],
            ($delegated = $row[1]->delegatedFrom($actorId)) !== null ? ($principals[$delegated] ?? null) : null,
            null,
        ), $slice);

        return self::paginator($entries, count($rows), $perPage, $page);
    }

    /**
     * Pedidos com decisão do ator ou tomada em nome dele (inclusive
     * decisões depois invalidadas por reavaliação).
     *
     * @return Builder<Order>
     */
    private function decidedQuery(BuyerActor $actor): Builder
    {
        $actorId = $actor->membership->id;

        return Order::query()
            ->where('buyer_company_id', $actor->company->id)
            ->whereExists(static function (QueryBuilder $decisions) use ($actorId): void {
                $decisions->selectRaw('1')
                    ->from('order_approval_decisions')
                    ->whereColumn('order_approval_decisions.order_id', 'orders.id')
                    ->where(static fn (QueryBuilder $mine): QueryBuilder => $mine
                        ->where('order_approval_decisions.actor_membership_id', $actorId)
                        ->orWhere('order_approval_decisions.principal_membership_id', $actorId));
            });
    }

    /**
     * @param  Builder<Order>  $query
     * @return LengthAwarePaginator<int, InboxEntry>
     */
    private function decidedPage(BuyerActor $actor, Builder $query, int $page, int $perPage): LengthAwarePaginator
    {
        $actorId = $actor->membership->id;
        $latest = OrderApprovalDecision::query()
            ->select('created_at')
            ->whereColumn('order_approval_decisions.order_id', 'orders.id')
            ->where(static fn (Builder $mine): Builder => $mine->where('actor_membership_id', $actorId)->orWhere('principal_membership_id', $actorId))
            ->orderByDesc('created_at')
            ->limit(1);

        $result = $query->orderByDesc($latest)->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        /** @var list<Order> $orders */
        $orders = array_values($result->items());

        /** @var array<string, OrderApprovalDecision> $decisions */
        $decisions = [];

        foreach (OrderApprovalDecision::query()
            ->whereIn('order_id', array_map(static fn (Order $order): string => $order->id, $orders))
            ->where(static fn (Builder $mine): Builder => $mine->where('actor_membership_id', $actorId)->orWhere('principal_membership_id', $actorId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get() as $decision) {
            $decisions[$decision->order_id] ??= $decision;
        }

        $levels = OrderApprovalStep::query()->whereKey(array_map(static fn (OrderApprovalDecision $d): string => $d->order_approval_step_id, array_values($decisions)))
            ->pluck('level_number', 'id')
            ->all();
        $names = MemberNames::ofMemberships(array_merge(...array_map(
            static fn (OrderApprovalDecision $d): array => [$d->actor_membership_id, $d->principal_membership_id],
            array_values($decisions),
        )));
        [$requesters, $centers, $levelCounts] = $this->details($orders);

        $entries = [];

        foreach ($orders as $order) {
            $decision = $decisions[$order->id] ?? null;

            $entries[] = new InboxEntry(
                $order,
                $requesters[$order->placed_by_user_id] ?? null,
                $order->cost_center_id !== null ? ($centers[$order->cost_center_id] ?? null) : null,
                $levelCounts[$order->id] ?? 0,
                null,
                null,
                $decision === null ? null : new InboxDecision(
                    $decision->decision,
                    (int) ($levels[$decision->order_approval_step_id] ?? 0),
                    $decision->created_at,
                    $decision->invalidated_at !== null,
                    $decision->actor_membership_id !== $actorId ? ($names[$decision->actor_membership_id] ?? null) : null,
                    $decision->principal_membership_id !== $actorId ? ($names[$decision->principal_membership_id] ?? null) : null,
                    $decision->comment,
                ),
            );
        }

        return self::paginator($entries, $result->total(), $perPage, $result->currentPage());
    }

    /**
     * Solicitantes, centros de custo e quantidade de níveis dos pedidos.
     *
     * @param  list<Order>  $orders
     * @return array{0: array<string, string>, 1: array<string, string>, 2: array<string, int>}
     */
    private function details(array $orders): array
    {
        if ($orders === []) {
            return [[], [], []];
        }

        $centerIds = array_values(array_unique(array_filter(array_map(static fn (Order $order): ?string => $order->cost_center_id, $orders), is_string(...))));
        /** @var array<string, string> $centers */
        $centers = $centerIds === [] ? [] : CostCenter::query()->whereKey($centerIds)->pluck('name', 'id')->all();
        /** @var array<string, int> $levels */
        $levels = OrderApprovalStep::query()
            ->whereIn('order_id', array_map(static fn (Order $order): string => $order->id, $orders))
            ->selectRaw('order_id, count(*) as levels')
            ->groupBy('order_id')
            ->pluck('levels', 'order_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            MemberNames::ofUsers(array_map(static fn (Order $order): string => $order->placed_by_user_id, $orders)),
            $centers,
            $levels,
        ];
    }

    /**
     * @param  list<array{0: Order, 1: PendingStepState}>  $rows
     */
    private static function amount(array $rows): Money
    {
        return Money::sum(array_map(static fn (array $row): Money => $row[0]->total_cents, $rows));
    }

    /**
     * @param  list<InboxEntry>  $entries
     * @return LengthAwarePaginator<int, InboxEntry>
     */
    private static function paginator(array $entries, int $total, int $perPage, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator($entries, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }
}
