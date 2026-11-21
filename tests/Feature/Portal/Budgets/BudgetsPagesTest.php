<?php

declare(strict_types=1);

use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Livewire\Portal\Budgets\BudgetShow;
use App\Livewire\Portal\Budgets\BudgetsIndex;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de orçamento do portal (portal.budgets, portal.budgets.show):
| posição por centro de custo e mês, cadastro e alteração do valor com
| erros de campo, histórico de movimentos com o pedido, permissão e
| isolamento por empresa.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $this->works = Fx::costCenter($this->scenario, $this->company, 'OBRAS');
    $this->maintenance = Fx::costCenter($this->scenario, $this->company, 'MANUT');
    $this->month = CarbonImmutable::now('UTC')->format('Y-m');

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->admin;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get($url);
    };

    $this->asBuyer = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->admin;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };

    $this->amount = fn (string $centerId, string $month): ?int => $this->scenario->system(static function () use ($centerId, $month): ?int {
        $value = DB::table('cost_center_budgets')->where('cost_center_id', $centerId)->where('period_start', $month.'-01')->value('amount_cents');

        return $value === null ? null : (int) $value;
    });
});

it('lista os centros de custo do mês com valor, reservado, consumido e disponível', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver)]]]);
    $budget = Fx::budget($this->scenario, $this->works, 100_000);
    Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->works->id]);

    ($this->page)(route('portal.budgets'))
        ->assertOk()
        ->assertSeeText('Orçamentos')
        ->assertSeeText('OBRAS · Centro OBRAS')
        ->assertSeeInOrder(['R$ 1.000,00', 'R$ 300,00', 'R$ 0,00', 'R$ 700,00'])
        ->assertSeeText('MANUT · Centro MANUT')
        ->assertSeeText('Sem orçamento no mês')
        ->assertSeeText('Total dos orçamentos do mês')
        ->assertSee(route('portal.budgets.show', $budget), escape: false);
});

it('cadastra e altera o valor do mês pela tela', function (): void {
    ($this->asBuyer)(fn () => Livewire::test(BudgetsIndex::class)
        ->assertSet('month', $this->month)
        ->set('costCenterId', $this->maintenance->id)
        ->set('amount', '12.500,50')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('amount', '')
        ->assertSee('salvo: R$ 12.500,50.')
        ->call('edit', $this->maintenance->id)
        ->assertSet('costCenterId', $this->maintenance->id)
        ->assertSet('amount', '12500,50')
        ->set('amount', '15000')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('R$ 15.000,00'));

    expect(($this->amount)($this->maintenance->id, $this->month))->toBe(1_500_000);
});

it('grava no mês escolhido e navega entre meses', function (): void {
    ($this->asBuyer)(fn () => Livewire::test(BudgetsIndex::class)
        ->set('month', '2027-02')
        ->assertSee('Centros de custo em fevereiro de 2027')
        ->set('costCenterId', $this->works->id)
        ->set('amount', '800,00')
        ->call('save')
        ->assertHasNoErrors()
        ->call('nextMonth')
        ->assertSet('month', '2027-03')
        ->assertSee('Sem orçamento no mês')
        ->call('previousMonth')
        ->call('previousMonth')
        ->assertSet('month', '2027-01'));

    expect(($this->amount)($this->works->id, '2027-02'))->toBe(80_000)
        ->and(($this->amount)($this->works->id, $this->month))->toBeNull();
});

it('usa o mês atual quando o mês da URL é inválido', function (array $query): void {
    ($this->page)(route('portal.budgets', $query))
        ->assertOk()
        ->assertSeeText('Centros de custo em '.BudgetsIndex::monthLabel(CarbonImmutable::parse(now('America/Sao_Paulo')->format('Y-m').'-01')));
})->with([
    'texto' => [['mes' => 'novembro']],
    'mês 13' => [['mes' => '2026-13']],
    'fora do intervalo' => [['mes' => '1999-01']],
    'lista' => [['mes' => ['2026-11']]],
]);

it('mostra os erros no campo', function (string $centerKey, string $amount, string $field, string $message): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver)]]]);
    Fx::budget($this->scenario, $this->works, 100_000);
    Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->works->id]);

    $centerId = match ($centerKey) {
        'obras' => $this->works->id,
        'vazio' => '',
        'outra empresa' => Fx::costCenter($this->scenario, $this->scenario->tenant->addBuyerCompany(), 'ALHEIO')->id,
        'malformado' => 'OBRAS',
    };

    ($this->asBuyer)(fn () => Livewire::test(BudgetsIndex::class)
        ->set('costCenterId', $centerId)
        ->set('amount', $amount)
        ->call('save')
        ->assertHasErrors([$field])
        ->assertSee($message));

    expect(($this->amount)($this->works->id, $this->month))->toBe(100_000);
})->with([
    'abaixo do reservado' => ['obras', '299,99', 'amount', 'O valor não pode ficar abaixo do já reservado e consumido no mês (R$ 300,00).'],
    'texto' => ['obras', 'mil reais', 'amount', 'Informe um valor em reais, por exemplo 15.000,00.'],
    'negativo' => ['obras', '-10,00', 'amount', 'O valor do orçamento não pode ser negativo.'],
    'vazio' => ['obras', '', 'amount', 'Informe o valor do orçamento do mês.'],
    'acima do limite' => ['obras', '99999999999999999', 'amount', 'Valor acima do limite suportado.'],
    'sem centro de custo' => ['vazio', '100,00', 'costCenterId', 'Selecione o centro de custo.'],
    'centro de outra empresa' => ['outra empresa', '100,00', 'costCenterId', 'Selecione um centro de custo da sua empresa.'],
    'centro malformado' => ['malformado', '100,00', 'costCenterId', 'Selecione um centro de custo da sua empresa.'],
]);

it('trata como inexistente o centro de custo adulterado na edição', function (string $case): void {
    $id = $case === 'outra empresa'
        ? Fx::costCenter($this->scenario, $this->scenario->tenant->addBuyerCompany(), 'ALHEIO')->id
        : 'OBRAS';

    ($this->asBuyer)(fn () => Livewire::test(BudgetsIndex::class)->call('edit', $id)->assertNotFound());
})->with(['outra empresa', 'malformado']);

it('mostra o histórico de movimentos com o pedido', function (): void {
    $approver = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    Fx::rule($this->scenario, $this->company, [[1, [$approver]]]);
    $budget = Fx::budget($this->scenario, $this->works, 100_000);
    $pending = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->works->id]);
    $other = $this->scenario->buyerWithCart(quantity: '2', sameCompany: true);
    $rejected = Fx::place($this->scenario, $other, ['costCenterId' => $this->works->id]);
    Fx::decide($this->scenario, $approver, $rejected, App\Enums\Approvals\ApprovalDecision::Rejected, 'Compra fora do planejamento');

    ($this->page)(route('portal.budgets.show', $budget))
        ->assertOk()
        ->assertSeeText('Orçamento de OBRAS · Centro OBRAS')
        ->assertSeeInOrder(['Valor', 'R$ 1.000,00', 'Reservado', 'R$ 300,00', 'Consumido', 'R$ 0,00', 'Disponível', 'R$ 700,00'])
        ->assertSeeInOrder([$rejected->number, 'Liberação', '− R$ 200,00', $rejected->number, 'Reserva', '+ R$ 200,00', $pending->number, 'Reserva', '+ R$ 300,00'])
        ->assertSee(route('portal.orders.show', $pending->id), escape: false)
        ->assertSeeText(User::query()->findOrFail($this->buyer['membership']->user_id)->name);
});

it('não mostra link para o pedido a quem não vê os pedidos da empresa', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver)]]]);
    $budget = Fx::budget($this->scenario, $this->works, 100_000);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->works->id]);
    Catalog::revokePermission($this->scenario->tenant, SystemRole::BuyerAdmin, Permission::PortalOrdersViewAll);

    ($this->page)(route('portal.budgets.show', $budget))
        ->assertOk()
        ->assertSeeText($order->number)
        ->assertDontSee(route('portal.orders.show', $order->id), escape: false);
});

it('nega as telas de orçamento sem portal.budgets ou fora do contexto de comprador', function (string $case): void {
    $budget = Fx::budget($this->scenario, $this->works, 100_000);
    $member = match ($case) {
        'comprador' => $this->buyer['membership'],
        'aprovador' => $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver),
        'equipe' => $this->scenario->tenant->ownerMembership,
    };

    ($this->page)(route('portal.budgets'), $member)->assertForbidden();
    ($this->page)(route('portal.budgets.show', $budget), $member)->assertForbidden();
})->with(['comprador', 'aprovador', 'equipe']);

it('refaz a autorização na gravação após a perda da permissão', function (): void {
    $component = ($this->asBuyer)(fn () => Livewire::test(BudgetsIndex::class)
        ->set('costCenterId', $this->works->id)
        ->set('amount', '100,00')
        ->assertOk());

    Catalog::revokePermission($this->scenario->tenant, SystemRole::BuyerAdmin, Permission::PortalBudgets);

    ($this->asBuyer)(fn () => $component->call('save')->assertForbidden());

    expect(($this->amount)($this->works->id, $this->month))->toBeNull();
});

it('responde 404 a orçamento de outra empresa, de outro distribuidor ou malformado', function (string $case): void {
    $id = match ($case) {
        'outra empresa' => Fx::budget($this->scenario, Fx::costCenter($this->scenario, $this->scenario->tenant->addBuyerCompany(), 'ALHEIO'), 10_000),
        'outro distribuidor' => (function (): string {
            $foreign = OrderingScenario::create();

            return Fx::budget($foreign, Fx::costCenter($foreign, $foreign->tenant->addBuyerCompany(), 'ALHEIO'), 10_000);
        })(),
        'inexistente' => (string) Str::uuid7(),
        'malformado' => 'orcamento-1',
    };

    ($this->page)(route('portal.budgets.show', $id))->assertNotFound();
})->with(['outra empresa', 'outro distribuidor', 'inexistente', 'malformado']);

it('protege o orçamento exibido e a mensagem contra alteração pelo cliente', function (): void {
    $budget = Fx::budget($this->scenario, $this->works, 100_000);
    $other = Fx::budget($this->scenario, Fx::costCenter($this->scenario, $this->scenario->tenant->addBuyerCompany(), 'ALHEIO'), 10_000);

    ($this->asBuyer)(function () use ($budget, $other): void {
        $show = Livewire::test(BudgetShow::class, ['budget' => $budget]);
        $index = Livewire::test(BudgetsIndex::class);

        expect(fn () => $show->set('budgetId', $other))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $index->set('message', 'Orçamento salvo'))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});
