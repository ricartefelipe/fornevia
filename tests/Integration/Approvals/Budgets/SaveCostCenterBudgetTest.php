<?php

declare(strict_types=1);

use App\Actions\Approvals\SaveCostCenterBudget;
use App\Domain\Approvals\Exceptions\BudgetExceeded;
use App\Domain\Approvals\Exceptions\InvalidBudget;
use App\Domain\Shared\Money;
use App\Enums\Identity\SystemRole;
use App\Models\Approvals\CostCenterBudget;
use App\Models\Buyers\CostCenter;
use App\Models\Identity\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Concurrency\ConcurrencyHarness;

/*
| Orçamento mensal por centro de custo (RN-055) gravado pelo portal:
| criação e alteração do valor sob lock do orçamento, sem ficar abaixo de
| reservado + consumido; somente com portal.budgets, no contexto da própria
| empresa compradora; auditoria de cada alteração.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $this->center = Fx::costCenter($this->scenario, $this->company);
    $this->month = CarbonImmutable::now('UTC')->format('Y-m');

    $this->save = fn (mixed $centerId, string $month, int $cents, ?Membership $as = null): CostCenterBudget => $this->scenario->tenant->actingAs(
        $as ?? $this->admin,
        static fn (): CostCenterBudget => app(SaveCostCenterBudget::class)->handle($centerId, $month, Money::ofCents($cents)),
    );

    $this->stored = fn (): array => $this->scenario->system(fn (): array => DB::table('cost_center_budgets')
        ->where('cost_center_id', $this->center->id)
        ->orderBy('period_start')
        ->get(['period_start', 'amount_cents', 'reserved_cents', 'consumed_cents'])
        ->map(static fn (object $row): array => [(string) $row->period_start, (int) $row->amount_cents, (int) $row->reserved_cents, (int) $row->consumed_cents])
        ->all());

    $this->audits = fn (): array => $this->scenario->system(static fn (): array => DB::table('audit_logs')
        ->where('subject_type', 'cost_center_budget')
        ->orderBy('created_at')
        ->orderBy('id')
        ->get(['action', 'data', 'actor_membership_id'])
        ->map(static fn (object $row): array => ['action' => $row->action, 'data' => json_decode((string) $row->data, true), 'actor' => $row->actor_membership_id])
        ->all());
});

it('cria o orçamento do mês no primeiro dia, sem saldos, e audita', function (): void {
    $budget = ($this->save)($this->center->id, '2026-12', 1_500_000);

    expect($budget->period_start->toDateString())->toBe('2026-12-01')
        ->and($budget->amount_cents->cents)->toBe(1_500_000)
        ->and($budget->position()->available()->cents)->toBe(1_500_000)
        ->and(($this->stored)())->toBe([['2026-12-01', 1_500_000, 0, 0]])
        // jsonb não preserva a ordem das chaves.
        ->and(($this->audits)())->toEqual([[
            'action' => 'cost_center_budget.created',
            'data' => ['cost_center_id' => $this->center->id, 'period_start' => '2026-12-01', 'amount_cents' => 1_500_000],
            'actor' => $this->admin->id,
        ]]);
});

it('altera o valor do mês registrando o anterior e ignora valor igual ao atual', function (): void {
    ($this->save)($this->center->id, '2026-12', 1_500_000);
    ($this->save)($this->center->id, '2026-12', 2_000_000);
    ($this->save)($this->center->id, '2026-12', 2_000_000);
    ($this->save)($this->center->id, '2026-12', 800_000);

    $audits = ($this->audits)();

    expect(($this->stored)())->toBe([['2026-12-01', 800_000, 0, 0]])
        ->and(array_column($audits, 'action'))->toBe(['cost_center_budget.created', 'cost_center_budget.updated', 'cost_center_budget.updated'])
        ->and([$audits[1]['data']['from_cents'], $audits[1]['data']['to_cents']])->toBe([1_500_000, 2_000_000])
        ->and([$audits[2]['data']['from_cents'], $audits[2]['data']['to_cents']])->toBe([2_000_000, 800_000]);
});

it('mantém um orçamento por centro de custo e mês, independente entre meses', function (): void {
    ($this->save)($this->center->id, '2026-11', 100_000);
    ($this->save)($this->center->id, '2026-12', 200_000);
    ($this->save)($this->center->id, '2026-11', 150_000);

    expect(($this->stored)())->toBe([['2026-11-01', 150_000, 0, 0], ['2026-12-01', 200_000, 0, 0]]);
});

it('não deixa o valor ficar abaixo do reservado e consumido no mês', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver)]]], ['min_total_cents' => 20_000]);
    $budgetId = Fx::budget($this->scenario, $this->center, 100_000);

    // Reserva de R$ 300,00 (aguardando aprovação) e consumo de R$ 100,00 (sem regra aplicável).
    Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);
    Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true), ['costCenterId' => $this->center->id]);
    expect(Fx::budgetState($this->scenario, $budgetId))->toBe(['reserved' => 30_000, 'consumed' => 10_000]);

    try {
        ($this->save)($this->center->id, $this->month, 39_999);
        $this->fail('Redução abaixo do utilizado deveria ser recusada.');
    } catch (BudgetExceeded $e) {
        expect($e->getMessage())->toBe('O valor não pode ficar abaixo do já reservado e consumido no mês (R$ 400,00).')
            ->and($e->details())->toBe(['field' => 'amount', 'used_cents' => 40_000]);
    }

    $budget = ($this->save)($this->center->id, $this->month, 40_000);

    // A Action grava somente o valor: reservado e consumido continuam os dos movimentos.
    expect($budget->position()->available()->cents)->toBe(0)
        ->and(Fx::budgetState($this->scenario, $budgetId))->toBe(['reserved' => 30_000, 'consumed' => 10_000])
        ->and($this->scenario->system(static fn (): int => DB::table('budget_movements')->count()))->toBe(2);
});

it('valida o mês e o valor antes de gravar', function (string $month, int $cents, string $field, string $message): void {
    try {
        ($this->save)($this->center->id, $month, $cents);
        $this->fail('Entrada inválida deveria ser recusada.');
    } catch (InvalidBudget $e) {
        expect($e->details()['field'] ?? null)->toBe($field)
            ->and($e->getMessage())->toBe($message);
    }

    expect(($this->stored)())->toBe([]);
})->with([
    'mês por extenso' => ['novembro', 100, 'month', 'Informe o mês no formato AAAA-MM, por exemplo 2026-11.'],
    'mês 13' => ['2026-13', 100, 'month', 'Informe o mês no formato AAAA-MM, por exemplo 2026-11.'],
    'data completa' => ['2026-11-01', 100, 'month', 'Informe o mês no formato AAAA-MM, por exemplo 2026-11.'],
    'antes de 2000' => ['1999-12', 100, 'month', 'Informe um mês entre 2000 e 2099.'],
    'depois de 2099' => ['2100-01', 100, 'month', 'Informe um mês entre 2000 e 2099.'],
    'valor negativo' => ['2026-11', -1, 'amount', 'O valor do orçamento não pode ser negativo.'],
]);

it('não cria orçamento para centro de custo inativo, mas permite ajustar o existente', function (): void {
    ($this->save)($this->center->id, '2026-11', 100_000);
    $this->scenario->system(fn () => CostCenter::query()->whereKey($this->center->id)->update(['is_active' => false]));

    expect(fn () => ($this->save)($this->center->id, '2026-12', 100_000))->toThrow(InvalidBudget::class, 'inativo');

    ($this->save)($this->center->id, '2026-11', 120_000);

    expect(($this->stored)())->toBe([['2026-11-01', 120_000, 0, 0]]);
});

it('exige portal.budgets no contexto da empresa compradora', function (string $case): void {
    $member = match ($case) {
        'comprador' => $this->buyer['membership'],
        'aprovador' => $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver),
        'proprietário do distribuidor' => $this->scenario->tenant->ownerMembership,
        'administrador do distribuidor' => $this->scenario->tenant->addStaff(SystemRole::Admin),
    };

    expect(fn () => ($this->save)($this->center->id, '2026-11', 100_000, $member))->toThrow(AuthorizationException::class)
        ->and(($this->stored)())->toBe([]);
})->with(['comprador', 'aprovador', 'proprietário do distribuidor', 'administrador do distribuidor']);

it('trata centro de custo de outra empresa, de outro distribuidor ou malformado como inexistente', function (string $case): void {
    $centerId = match ($case) {
        'outra empresa' => Fx::costCenter($this->scenario, $this->scenario->tenant->addBuyerCompany(), 'ALHEIO')->id,
        'outro distribuidor' => (function (): string {
            $foreign = OrderingScenario::create();

            return Fx::costCenter($foreign, $foreign->tenant->addBuyerCompany(), 'ALHEIO')->id;
        })(),
        'inexistente' => (string) Str::uuid7(),
        'malformado' => 'OBRAS',
    };

    expect(fn () => ($this->save)($centerId, '2026-11', 100_000))->toThrow(ModelNotFoundException::class)
        ->and($this->scenario->system(static fn (): int => DB::table('cost_center_budgets')->count()))->toBe(0);
})->with(['outra empresa', 'outro distribuidor', 'inexistente', 'malformado']);

it('não altera o orçamento de outra empresa com o mesmo período', function (): void {
    $other = $this->scenario->tenant->addBuyerCompany();
    $otherCenter = Fx::costCenter($this->scenario, $other, 'OBRAS');
    $otherBudget = Fx::budget($this->scenario, $otherCenter, 70_000, '2026-11-01');
    $otherAdmin = $this->scenario->tenant->addBuyerUser($other, SystemRole::BuyerAdmin);

    ($this->save)($this->center->id, '2026-11', 100_000);
    expect(fn () => ($this->save)($otherCenter->id, '2026-11', 1, $this->admin))->toThrow(ModelNotFoundException::class);
    ($this->save)($otherCenter->id, '2026-11', 90_000, $otherAdmin);

    expect($this->scenario->system(static fn (): array => DB::table('cost_center_budgets')->orderBy('amount_cents')->pluck('amount_cents')->map(static fn (mixed $c): int => (int) $c)->all()))
        ->toBe([90_000, 100_000])
        ->and($this->scenario->system(static fn (): int => (int) DB::table('cost_center_budgets')->where('id', $otherBudget)->value('amount_cents')))->toBe(90_000);
});

it('bloqueia o orçamento antes de decidir: espera a reserva em andamento e enxerga o saldo dela', function (): void {
    $approver = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    Fx::rule($this->scenario, $this->company, [[1, [$approver]]]);
    $budgetId = Fx::budget($this->scenario, $this->center, 50_000);

    $harness = new ConcurrencyHarness;
    $hold = $harness->gate('reserva-segura');

    try {
        // Submissão para aprovação parada antes do commit, com o orçamento bloqueado e R$ 300,00 reservados.
        $checkout = $harness->start('place-order', $this->scenario->workerArgs($this->buyer, [
            'cost_center_id' => $this->center->id, 'hold' => 'reserva-segura',
        ]), 'submissao');
        $harness->waitUntilWaitingAt('reserva-segura', 1);

        // A alteração espera o lock do orçamento já na leitura (SELECT ... FOR UPDATE),
        // e não depois de decidir com o saldo anterior à reserva.
        $blocked = null;

        try {
            $this->scenario->tenant->actingAs($this->admin, function (): void {
                DB::statement("set local lock_timeout = '300ms'");
                app(SaveCostCenterBudget::class)->handle($this->center->id, $this->month, Money::ofCents(25_000));
            });
        } catch (QueryException $e) {
            $blocked = $e;
        }

        $hold->release();
        $result = $checkout->result();
    } finally {
        $harness->shutdown();
    }

    expect($blocked)->not->toBeNull()
        ->and($blocked?->getCode())->toBe('55P03')
        ->and(strtolower((string) $blocked?->getSql()))->toContain('from "cost_center_budgets"')
        ->and(strtolower((string) $blocked?->getSql()))->toContain('for update')
        ->and($result['ok'])->toBeTrue()
        ->and(Fx::budgetState($this->scenario, $budgetId))->toBe(['reserved' => 30_000, 'consumed' => 0]);

    // Depois do commit da reserva, a redução enxerga o reservado.
    expect(fn () => ($this->save)($this->center->id, $this->month, 25_000))->toThrow(BudgetExceeded::class, 'R$ 300,00');
    expect(($this->save)($this->center->id, $this->month, 30_000)->position()->available()->cents)->toBe(0);
});
