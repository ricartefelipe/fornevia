<?php

declare(strict_types=1);

namespace App\Livewire\Portal\Budgets;

use App\Actions\Approvals\SaveCostCenterBudget;
use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Domain\Approvals\BudgetPosition;
use App\Domain\Shared\AmountOutOfRange;
use App\Domain\Shared\BusinessRuleViolation;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Approvals\CostCenterBudget;
use App\Models\Buyers\BuyerUnit;
use App\Models\Buyers\CostCenter;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Validation\BusinessDate;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Orçamentos por centro de custo e mês (RN-055), para quem tem
 * portal.budgets: valor, reservado (pedidos aguardando aprovação),
 * consumido (pedidos confirmados) e disponível; cadastro e alteração do
 * valor do mês por SaveCostCenterBudget, que bloqueia o orçamento e recusa
 * valor abaixo de reservado + consumido.
 *
 * O mês vem da URL (entrada não confiável): fora do formato ou do
 * intervalo aceito, vale o mês atual. Centros de custo e orçamentos são
 * sempre lidos da empresa do contexto; identificadores recebidos do
 * cliente fora dela equivalem a registro inexistente.
 */
#[Layout('layouts.app')]
#[Title('Orçamentos')]
final class BudgetsIndex extends Component
{
    /** @var array<int, string> */
    public const array MONTHS = [
        1 => 'janeiro', 2 => 'fevereiro', 3 => 'março', 4 => 'abril', 5 => 'maio', 6 => 'junho',
        7 => 'julho', 8 => 'agosto', 9 => 'setembro', 10 => 'outubro', 11 => 'novembro', 12 => 'dezembro',
    ];

    #[Url(as: 'mes', except: '')]
    public string $month = '';

    /** Centro de custo do formulário (entrada não confiável). */
    public string $costCenterId = '';

    /** Valor digitado em reais (pt-BR). */
    public string $amount = '';

    #[Locked]
    public ?string $message = null;

    public function mount(PortalBuyer $buyer): void
    {
        $buyer->require(Permission::PortalBudgets);
        $this->month = $this->period()->format('Y-m');
    }

    public function updatedMonth(): void
    {
        $this->month = $this->period()->format('Y-m');
        $this->resetForm();
        $this->message = null;
    }

    public function previousMonth(): void
    {
        $this->moveTo($this->period()->subMonthNoOverflow());
    }

    public function nextMonth(): void
    {
        $this->moveTo($this->period()->addMonthNoOverflow());
    }

    /**
     * Preenche o formulário com o centro de custo e o valor atual do mês.
     */
    public function edit(mixed $costCenterId, PortalBuyer $buyer): void
    {
        $actor = $buyer->require(Permission::PortalBudgets);
        $center = CostCenter::query()
            ->where('buyer_company_id', $actor->company->id)
            ->findOrFail(UntrustedId::uuid($costCenterId, CostCenter::class));

        $budget = $this->budgetOf($center->id);

        $this->resetErrorBag();
        $this->message = null;
        $this->costCenterId = $center->id;
        $this->amount = $budget !== null ? self::moneyInput($budget->amount_cents) : '';
    }

    public function save(SaveCostCenterBudget $save, PortalBuyer $buyer): void
    {
        $this->resetErrorBag();
        $this->message = null;
        $buyer->require(Permission::PortalBudgets);

        $amount = $this->parsedAmount();

        if ($this->costCenterId === '') {
            $this->addError('costCenterId', 'Selecione o centro de custo.');
        }

        if ($amount === null || $this->costCenterId === '') {
            return;
        }

        $period = $this->period();

        try {
            $budget = $save->handle($this->costCenterId, $period->format('Y-m'), $amount);
        } catch (ModelNotFoundException) {
            $this->addError('costCenterId', 'Selecione um centro de custo da sua empresa.');

            return;
        } catch (BusinessRuleViolation $e) {
            $field = $e->details()['field'] ?? null;
            $this->addError(match ($field) {
                'cost_center' => 'costCenterId',
                'month' => 'month',
                default => 'amount',
            }, $e->getMessage());

            return;
        }

        $center = CostCenter::query()->findOrFail($budget->cost_center_id);
        $this->resetForm();
        $this->message = "Orçamento de {$center->code} · {$center->name} em ".self::monthLabel($period).' salvo: '.$budget->amount_cents->format().'.';
    }

    public function render(PortalBuyer $buyer): View
    {
        $actor = $buyer->require(Permission::PortalBudgets);
        $period = $this->period();
        $this->month = $period->format('Y-m');

        $budgets = CostCenterBudget::query()
            ->where('buyer_company_id', $actor->company->id)
            ->where('period_start', $period->toDateString())
            ->get()
            ->keyBy('cost_center_id');

        $centers = CostCenter::query()
            ->where('buyer_company_id', $actor->company->id)
            ->where(static fn ($query) => $query->where('is_active', true)->orWhereIn('id', $budgets->keys()->all()))
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        $units = BuyerUnit::query()->where('buyer_company_id', $actor->company->id)->pluck('name', 'id')->all();
        $rows = [];

        foreach ($centers as $center) {
            /** @var CostCenterBudget|null $budget */
            $budget = $budgets->get($center->id);
            $rows[] = [
                'center' => $center,
                'unit' => $center->buyer_unit_id !== null ? ($units[$center->buyer_unit_id] ?? null) : null,
                'budget' => $budget,
                'position' => $budget?->position(),
            ];
        }

        return view('livewire.portal.budgets.budgets-index', [
            'rows' => $rows,
            'totals' => self::totals($budgets->all()),
            'monthLabel' => self::monthLabel($period),
            'centerOptions' => $centers->filter(static fn (CostCenter $center): bool => $center->is_active || $budgets->has($center->id))
                ->mapWithKeys(static fn (CostCenter $center): array => [$center->id => "{$center->code} · {$center->name}".($center->is_active ? '' : ' (inativo)')])
                ->all(),
            'isFirstMonth' => $period->format('Y-m') === self::firstMonth(),
            'isLastMonth' => $period->format('Y-m') === self::lastMonth(),
        ]);
    }

    public static function monthLabel(CarbonImmutable $period): string
    {
        return self::MONTHS[$period->month].' de '.$period->year;
    }

    /**
     * Mês exibido: o da URL, quando válido; senão, o mês atual no fuso
     * comercial.
     */
    private function period(): CarbonImmutable
    {
        try {
            return SaveCostCenterBudget::period($this->month);
        } catch (BusinessRuleViolation) {
            return SaveCostCenterBudget::period(app(Clock::class)->today()->format('Y-m'));
        }
    }

    /**
     * Navegação entre meses, sem sair do intervalo de datas comerciais.
     */
    private function moveTo(CarbonImmutable $period): void
    {
        $month = $period->format('Y-m');

        if ($month >= self::firstMonth() && $month <= self::lastMonth()) {
            $this->month = $month;
        }

        $this->resetForm();
        $this->message = null;
    }

    private static function firstMonth(): string
    {
        return substr(BusinessDate::EARLIEST, 0, 7);
    }

    private static function lastMonth(): string
    {
        return substr(BusinessDate::LATEST, 0, 7);
    }

    private function budgetOf(string $costCenterId): ?CostCenterBudget
    {
        return CostCenterBudget::query()
            ->where('cost_center_id', $costCenterId)
            ->where('period_start', $this->period()->toDateString())
            ->first();
    }

    /**
     * Valor em reais digitado em pt-BR ("1.234,56", "1234,56" ou "1234.56").
     */
    private function parsedAmount(): ?Money
    {
        if (trim($this->amount) === '') {
            $this->addError('amount', 'Informe o valor do orçamento do mês.');

            return null;
        }

        try {
            $amount = Money::parse($this->amount);
        } catch (AmountOutOfRange) {
            $this->addError('amount', 'Valor acima do limite suportado.');

            return null;
        } catch (InvalidArgumentException|MathException) {
            $this->addError('amount', 'Informe um valor em reais, por exemplo 15.000,00.');

            return null;
        }

        if ($amount->isNegative()) {
            $this->addError('amount', 'O valor do orçamento não pode ser negativo.');

            return null;
        }

        return $amount;
    }

    private function resetForm(): void
    {
        $this->reset('costCenterId', 'amount');
        $this->resetErrorBag();
    }

    private static function moneyInput(Money $money): string
    {
        return str_replace('.', ',', $money->toDecimalString());
    }

    /**
     * Soma dos orçamentos do mês; null quando excede o limite de cálculo.
     *
     * @param  array<array-key, CostCenterBudget>  $budgets
     */
    private static function totals(array $budgets): ?BudgetPosition
    {
        if ($budgets === []) {
            return null;
        }

        try {
            return new BudgetPosition(
                Money::sum(array_map(static fn (CostCenterBudget $b): Money => $b->amount_cents, $budgets)),
                Money::sum(array_map(static fn (CostCenterBudget $b): Money => $b->reserved_cents, $budgets)),
                Money::sum(array_map(static fn (CostCenterBudget $b): Money => $b->consumed_cents, $budgets)),
            );
        } catch (AmountOutOfRange) {
            return null;
        }
    }
}
