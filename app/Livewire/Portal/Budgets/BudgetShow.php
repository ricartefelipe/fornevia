<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Budgets;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\BuyerActor;
use App\Enums\Identity\Permission;
use App\Models\Approvals\BudgetMovement;
use App\Models\Approvals\CostCenterBudget;
use App\Models\Buyers\CostCenter;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use App\Support\Http\UntrustedId;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Orçamento de um centro de custo no mês: posição atual e histórico de
 * movimentos (reserva na submissão para aprovação, consumo na confirmação,
 * liberação na rejeição, no cancelamento ou na reavaliação), com o pedido
 * de cada movimento. O orçamento é identificado por propriedade bloqueada e
 * recarregado da empresa do contexto a cada renderização.
 */
#[Layout('layouts.app')]
#[Title('Orçamento')]
final class BudgetShow extends Component
{
    use WithPagination;

    public const int PER_PAGE = 25;

    #[Locked]
    public string $budgetId = '';

    public function mount(string $budget, PortalBuyer $buyer): void
    {
        $this->budgetId = $this->budget($buyer->require(Permission::PortalBudgets), $budget)->id;
    }

    public function paginationView(): string
    {
        return 'livewire.portal.budgets.pagination';
    }

    public function render(PortalBuyer $buyer): View
    {
        $actor = $buyer->require(Permission::PortalBudgets);
        $budget = $this->budget($actor, $this->budgetId);
        $center = CostCenter::query()->findOrFail($budget->cost_center_id);
        $movements = $this->movements($budget);

        /** @var list<BudgetMovement> $page */
        $page = array_values($movements->items());
        $orders = Order::query()
            ->whereIn('id', array_values(array_unique(array_map(static fn (BudgetMovement $m): string => $m->order_id, $page))))
            ->get(['id', 'number', 'placed_by_membership_id'])
            ->keyBy('id');
        $actorIds = array_values(array_unique(array_filter(array_map(static fn (BudgetMovement $m): ?string => $m->actor_user_id, $page), is_string(...))));
        $seesAll = $buyer->can(Permission::PortalOrdersViewAll);

        return view('livewire.portal.budgets.budget-show', [
            'budget' => $budget,
            'center' => $center,
            'position' => $budget->position(),
            'monthLabel' => BudgetsIndex::monthLabel($budget->period_start),
            'movements' => $movements,
            'orders' => $orders,
            // Link para o pedido só quando a tela de pedidos o mostra a quem consulta.
            'linkable' => $orders->filter(static fn (Order $order): bool => $seesAll || $order->placed_by_membership_id === $actor->membership->id)->keys()->all(),
            'names' => $actorIds === [] ? [] : User::query()->whereIn('id', $actorIds)->pluck('name', 'id')->all(),
        ])->title("Orçamento {$center->code} · ".BudgetsIndex::monthLabel($budget->period_start));
    }

    private function budget(BuyerActor $actor, mixed $budgetId): CostCenterBudget
    {
        return CostCenterBudget::query()
            ->where('buyer_company_id', $actor->company->id)
            ->findOrFail(UntrustedId::uuid($budgetId, CostCenterBudget::class));
    }

    /**
     * @return LengthAwarePaginator<int, BudgetMovement>
     */
    private function movements(CostCenterBudget $budget): LengthAwarePaginator
    {
        $page = filter_var($this->getPage(), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $query = BudgetMovement::query()
            ->where('cost_center_budget_id', $budget->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $movements = $query->paginate(self::PER_PAGE, ['*'], 'page', is_int($page) ? $page : 1);

        // Página além da última (URL editada): volta à última existente.
        if ($movements->isEmpty() && $movements->total() > 0) {
            $this->setPage($movements->lastPage());
            $movements = $query->paginate(self::PER_PAGE, ['*'], 'page', $movements->lastPage());
        }

        return $movements;
    }
}
