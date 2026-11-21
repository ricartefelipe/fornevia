<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Actions\Ordering\Portal\Support\PortalBuyer;
use App\Data\Ordering\Portal\BuyerActor;
use App\Domain\Approvals\Exceptions\BudgetExceeded;
use App\Domain\Approvals\Exceptions\InvalidBudget;
use App\Domain\Shared\Money;
use App\Enums\Identity\Permission;
use App\Models\Approvals\CostCenterBudget;
use App\Models\Buyers\CostCenter;
use App\Services\Audit\AuditRecorder;
use App\Support\Clock\Clock;
use App\Support\Http\UntrustedId;
use App\Support\Tenancy\CurrentContext;
use App\Support\Tenancy\TenantDatabase;
use App\Support\Validation\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cadastra ou altera o valor do orçamento mensal de um centro de custo
 * (RN-055), no portal, por quem tem portal.budgets.
 *
 * - O centro de custo precisa ser da empresa do contexto (filtro explícito;
 *   a gravação roda no contexto de sistema do mesmo usuário, pois os
 *   orçamentos são somente leitura no contexto de comprador, ADR-024); fora
 *   dela, ou com identificador malformado, equivale a registro
 *   inexistente. Centro de custo inativo não recebe orçamento novo.
 * - O orçamento do mês é bloqueado (FOR UPDATE) antes de ler reservado e
 *   consumido: uma submissão ou confirmação concorrente termina antes (e a
 *   alteração enxerga a reserva dela) ou espera a alteração e decide com o
 *   novo valor (ADR-006, orçamento por último). O valor não fica abaixo de
 *   reservado + consumido; o banco garante o mesmo.
 * - Na criação concorrente do mesmo mês, a gravação que chega depois
 *   encontra a linha da primeira (ON CONFLICT DO NOTHING) e segue como
 *   alteração, sob o mesmo lock.
 * - A aplicação grava somente o valor; reservado e consumido mudam apenas
 *   por movimentos (BudgetLedger). Valor igual ao atual não gera alteração.
 */
final readonly class SaveCostCenterBudget
{
    public function __construct(
        private PortalBuyer $buyer,
        private CurrentContext $context,
        private AuditRecorder $audit,
        private TenantDatabase $db,
        private Clock $clock,
    ) {}

    /**
     * @param  mixed  $costCenterId  identificador recebido do cliente (não confiável)
     * @param  string  $month  mês no formato AAAA-MM
     */
    public function handle(mixed $costCenterId, string $month, Money $amount): CostCenterBudget
    {
        $actor = $this->buyer->require(Permission::PortalBudgets);
        $period = self::period($month);

        if ($amount->isNegative()) {
            throw new InvalidBudget('O valor do orçamento não pode ser negativo.', ['field' => 'amount']);
        }

        return DB::transaction(function () use ($actor, $costCenterId, $period, $amount): CostCenterBudget {
            // Orçamentos são somente leitura no contexto de comprador
            // (ADR-024): bloqueia e grava no contexto de sistema do mesmo
            // usuário; a auditoria fica no contexto de quem alterou.
            [$budget, $audit] = $this->db->asSystem(fn (): array => $this->save($actor, $costCenterId, $period, $amount));

            if ($audit !== null) {
                $this->audit->record($audit[0], $budget, $audit[1]);
            }

            return $budget;
        });
    }

    /**
     * @return array{0: CostCenterBudget, 1: array{0: string, 1: array<string, mixed>}|null} orçamento e auditoria
     */
    private function save(BuyerActor $actor, mixed $costCenterId, CarbonImmutable $period, Money $amount): array
    {
        $center = CostCenter::query()
            ->where('buyer_company_id', $actor->company->id)
            ->findOrFail(UntrustedId::uuid($costCenterId, CostCenter::class));

        $budget = $this->locked($center, $period);

        if ($budget === null) {
            if (! $center->is_active) {
                throw new InvalidBudget('O centro de custo está inativo e não recebe orçamento novo.', ['field' => 'cost_center']);
            }

            if ($this->insert($center, $period, $amount)) {
                $budget = $this->locked($center, $period) ?? throw new RuntimeException('Orçamento não encontrado após a criação.');

                return [$budget, ['cost_center_budget.created', [
                    'cost_center_id' => $center->id,
                    'period_start' => $period->toDateString(),
                    'amount_cents' => $amount->cents,
                ]]];
            }

            // Criado por outra gravação simultânea: segue como alteração.
            $budget = $this->locked($center, $period) ?? throw new RuntimeException('Orçamento não encontrado após a criação concorrente.');
        }

        return $this->change($budget, $center, $amount);
    }

    /**
     * Primeiro dia do mês informado (AAAA-MM), dentro do intervalo de datas
     * comerciais aceito pela aplicação.
     */
    public static function period(string $month): CarbonImmutable
    {
        $month = trim($month);

        if (preg_match('/^(\d{4})-(\d{2})$/D', $month, $parts) !== 1 || (int) $parts[2] < 1 || (int) $parts[2] > 12) {
            throw new InvalidBudget('Informe o mês no formato AAAA-MM, por exemplo 2026-11.', ['field' => 'month']);
        }

        $start = $month.'-01';

        if ($start < BusinessDate::EARLIEST || $start > BusinessDate::LATEST) {
            throw new InvalidBudget('Informe um mês entre 2000 e 2099.', ['field' => 'month']);
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC')
            ?: throw new InvalidBudget('Informe o mês no formato AAAA-MM, por exemplo 2026-11.', ['field' => 'month']);
    }

    /**
     * @return array{0: CostCenterBudget, 1: array{0: string, 1: array<string, mixed>}|null}
     */
    private function change(CostCenterBudget $budget, CostCenter $center, Money $amount): array
    {
        $previous = $budget->amount_cents;

        if ($previous->equals($amount)) {
            return [$budget, null];
        }

        $used = $budget->reserved_cents->plus($budget->consumed_cents);

        if ($amount->lessThan($used)) {
            throw new BudgetExceeded(
                'O valor não pode ficar abaixo do já reservado e consumido no mês ('.$used->format().').',
                ['field' => 'amount', 'used_cents' => $used->cents],
            );
        }

        $budget->forceFill(['amount_cents' => $amount])->save();

        return [$budget, ['cost_center_budget.updated', [
            'cost_center_id' => $center->id,
            'period_start' => $budget->period_start->toDateString(),
            'from_cents' => $previous->cents,
            'to_cents' => $amount->cents,
            'reserved_cents' => $budget->reserved_cents->cents,
            'consumed_cents' => $budget->consumed_cents->cents,
        ]]];
    }

    private function locked(CostCenter $center, CarbonImmutable $period): ?CostCenterBudget
    {
        return CostCenterBudget::query()
            ->where('buyer_company_id', $center->buyer_company_id)
            ->where('cost_center_id', $center->id)
            ->where('period_start', $period->toDateString())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Insere o orçamento do mês, sem saldos (colunas permitidas ao papel da
     * aplicação). Retorna falso quando outra gravação já o criou.
     */
    private function insert(CostCenter $center, CarbonImmutable $period, Money $amount): bool
    {
        $now = $this->clock->now();

        return DB::table('cost_center_budgets')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->tenantId(),
            'buyer_company_id' => $center->buyer_company_id,
            'cost_center_id' => $center->id,
            'period_start' => $period->toDateString(),
            'amount_cents' => $amount->cents,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
