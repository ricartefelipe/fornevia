<?php

declare(strict_types=1);

namespace App\Services\Approvals;

use App\Domain\Approvals\Exceptions\BudgetExceeded;
use App\Domain\Shared\Money;
use App\Enums\Approvals\BudgetMovementType;
use App\Models\Approvals\CostCenterBudget;
use App\Models\Ordering\Order;
use App\Support\Clock\Clock;
use App\Support\Tenancy\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Orçamento por centro de custo (RN-055): reserva na submissão para
 * aprovação, consumo na confirmação e liberação na rejeição ou no
 * cancelamento. Só há controle quando o centro de custo tem orçamento
 * cadastrado para o mês do pedido.
 *
 * O orçamento é bloqueado (FOR UPDATE) antes de decidir; os saldos mudam
 * apenas por movimentos (trigger), e o banco garante
 * reservado + consumido <= valor. Ordem de locks (ADR-006): saldos de
 * estoque → conta de crédito → orçamento → contadores.
 */
final readonly class BudgetLedger
{
    public function __construct(
        private CurrentContext $context,
        private Clock $clock,
    ) {}

    public function budgetFor(string $costCenterId, CarbonImmutable $date, bool $lock = false): ?CostCenterBudget
    {
        return CostCenterBudget::query()
            ->where('cost_center_id', $costCenterId)
            // Mês no fuso comercial: pedido às 22h do último dia (Brasília) é do próprio mês.
            ->where('period_start', $date->setTimezone((string) config('app.business_timezone', 'America/Sao_Paulo'))->startOfMonth()->toDateString())
            ->when($lock, static fn ($q) => $q->lockForUpdate())
            ->first();
    }

    /**
     * Reserva o total do pedido submetido para aprovação.
     */
    public function reserve(Order $order): void
    {
        $budget = $this->lockedBudget($order);

        if ($budget === null) {
            return;
        }

        $this->assertFits($budget, $order->total_cents);
        $this->movement($budget, $order, BudgetMovementType::Reservation, $order->total_cents->cents, 0);
    }

    /**
     * Consome o total do pedido confirmado, convertendo a reserva existente
     * (o total pode ter mudado na reavaliação; a diferença precisa caber).
     */
    public function consume(Order $order): void
    {
        $budget = $this->lockedBudget($order);

        if ($budget === null) {
            return;
        }

        $reserved = $this->reservedFor($order, $budget);
        $additional = $order->total_cents->minus(Money::ofCents($reserved));

        if ($additional->isPositive()) {
            $this->assertFits($budget, $additional);
        }

        $this->movement($budget, $order, BudgetMovementType::Consumption, -$reserved, $order->total_cents->cents);
    }

    /**
     * Libera a reserva ainda aberta do pedido (rejeição, cancelamento ou
     * reavaliação com novo total).
     */
    public function release(Order $order): void
    {
        $budget = $this->lockedBudget($order);

        if ($budget === null) {
            return;
        }

        $reserved = $this->reservedFor($order, $budget);

        if ($reserved > 0) {
            $this->movement($budget, $order, BudgetMovementType::Release, -$reserved, 0);
        }
    }

    private function lockedBudget(Order $order): ?CostCenterBudget
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Movimentos de orçamento exigem transação aberta.');
        }

        if ($order->cost_center_id === null) {
            return null;
        }

        return $this->budgetFor($order->cost_center_id, $order->submitted_at ?? $this->clock->now(), lock: true);
    }

    private function reservedFor(Order $order, CostCenterBudget $budget): int
    {
        return (int) DB::table('budget_movements')
            ->where('cost_center_budget_id', $budget->id)
            ->where('order_id', $order->id)
            ->sum('reserved_delta');
    }

    private function assertFits(CostCenterBudget $budget, Money $amount): void
    {
        $position = $budget->position();

        if (! $position->fits($amount)) {
            throw new BudgetExceeded(
                'O orçamento do centro de custo no mês não comporta este pedido. Disponível: '.$position->available()->format().'.',
                ['field' => 'cost_center', 'available_cents' => $position->available()->cents, 'required_cents' => $amount->cents],
            );
        }
    }

    private function movement(CostCenterBudget $budget, Order $order, BudgetMovementType $type, int $reservedDelta, int $consumedDelta): void
    {
        DB::table('budget_movements')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $budget->tenant_id,
            'buyer_company_id' => $budget->buyer_company_id,
            'cost_center_budget_id' => $budget->id,
            'order_id' => $order->id,
            'type' => $type->value,
            'reserved_delta' => $reservedDelta,
            'consumed_delta' => $consumedDelta,
            'actor_user_id' => $this->context->get()?->userId,
            'created_at' => $this->clock->now(),
        ]);

        $budget->refresh();
    }
}
