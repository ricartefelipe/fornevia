<?php

declare(strict_types=1);

use App\Enums\Approvals\ApprovalDecision;
use App\Enums\Identity\SystemRole;
use App\Enums\Ordering\ApprovalStatus;
use App\Enums\Ordering\CommercialStatus;
use App\Livewire\Portal\Approvals\Inbox\ApprovalShow;
use App\Livewire\Portal\Approvals\Inbox\ApprovalsInbox;
use App\Models\Approvals\OrderApprovalDecision;
use App\Models\Approvals\OrderApprovalStep;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Integration\Approvals\Inbox\InboxFixtures as Inbox;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas da fila de aprovação (portal.approvals, portal.approvals.show):
| fila com abas, detalhe com níveis e decisões, aprovar, rejeitar com
| motivo, reatribuir o nível bloqueado, identificadores adulterados e
| permissões.
*/

beforeEach(function (): void {
    $this->withoutVite();

    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->ana = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->bruno = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::Approver);
    $this->admin = $this->scenario->tenant->addBuyerUser($this->company, SystemRole::BuyerAdmin);
    $this->center = Fx::costCenter($this->scenario, $this->company);

    $this->page = function (string $url, Membership $membership): TestResponse {
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get($url);
    };

    $this->as = function (Membership $membership, Closure $callback): mixed {
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };

    $this->stored = fn (Order $order): Order => $this->scenario->system(static fn (): Order => Order::query()->findOrFail($order->id));
});

it('lista na fila do aprovador o solicitante, o centro de custo, a regra, o nível, o prazo e o total', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [1, [$this->bruno]]]);
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);

    ($this->page)(route('portal.approvals'), $this->ana)
        ->assertOk()
        ->assertSeeText('Aprovações')
        ->assertSeeText('A aprovação interna não garante estoque nem crédito no distribuidor')
        ->assertSeeText('Aguardando sua decisão')
        ->assertSeeText('Decididos por você')
        ->assertDontSeeText('Bloqueados')
        ->assertSeeText($order->number)
        ->assertSeeText(Inbox::name($this->buyer['membership']))
        ->assertSeeText('Centro OBRAS')
        ->assertSeeText('Compras acima do limite')
        ->assertSeeText('Nível 1 de 2')
        ->assertSeeText('0 de 1 aprovação')
        ->assertSeeText('menos de 1 hora')
        ->assertSeeText('R$ 300,00')
        ->assertSeeText('1 pedido, somando R$ 300,00.')
        ->assertSee(route('portal.approvals.show', $order->id), escape: false);

    ($this->page)(route('portal.approvals'), $this->bruno)
        ->assertOk()
        ->assertSeeText('Nenhum pedido aguardando sua decisão')
        ->assertDontSeeText($order->number);
});

it('mostra os decididos e, a quem administra as regras, os bloqueados', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $decided = Fx::place($this->scenario, $this->buyer);
    Fx::decide($this->scenario, $this->ana, $decided, ApprovalDecision::Rejected, 'Fora do orçamento do trimestre');

    ($this->page)(route('portal.approvals', ['aba' => 'decididos']), $this->ana)
        ->assertOk()
        ->assertSeeText($decided->number)
        ->assertSeeText('Rejeitado')
        ->assertSeeText('Nível 1')
        ->assertSeeText('Reprovado');

    $blocked = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true));
    Inbox::revoke($this->scenario, $this->ana);

    ($this->page)(route('portal.approvals', ['aba' => 'bloqueados']), $this->admin)
        ->assertOk()
        ->assertSeeText('Bloqueados')
        ->assertSeeText($blocked->number)
        ->assertSeeText('Sem aprovadores suficientes')
        ->assertDontSeeText($decided->number);
});

it('ignora aba e página adulteradas na URL', function (array $query): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    ($this->page)(route('portal.approvals', $query), $this->ana)
        ->assertOk()
        ->assertSeeText($order->number)
        ->assertDontSeeText('Bloqueados');
})->with([
    'aba inexistente' => [['aba' => 'todas']],
    'aba como lista' => [['aba' => ['decididos']]],
    'bloqueados sem permissão' => [['aba' => 'bloqueados']],
    'página além da última' => [['page' => '50']],
    'página não numérica' => [['page' => 'fim']],
]);

it('exibe o detalhe com itens, totais, condições, níveis, aprovadores e histórico', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]], [2, [$this->bruno, $this->admin]]], name: 'Compras de obra');
    $order = Fx::place($this->scenario, $this->buyer, ['costCenterId' => $this->center->id]);
    Fx::decide($this->scenario, $this->ana, $order, ApprovalDecision::Approved, 'Dentro do previsto');

    ($this->page)(route('portal.approvals.show', $order->id), $this->bruno)
        ->assertOk()
        ->assertSeeText("Aprovação do pedido {$order->number}")
        ->assertSeeText('A aprovação interna não garante estoque nem crédito no distribuidor.')
        ->assertSeeText('Disjuntor bipolar 32A')
        ->assertSeeText('3 UN')
        ->assertSeeText('R$ 100,00')
        ->assertSeeText('R$ 300,00')
        ->assertSeeText('Compras de obra')
        ->assertSeeText('Nível 2 de 2')
        ->assertSeeText('Quórum: 1 de 1 aprovação')
        ->assertSeeText('Quórum: 0 de 2 aprovações')
        ->assertSeeText(Inbox::name($this->ana))
        ->assertSeeText('Aprovou')
        ->assertSeeText('“Dentro do previsto”')
        ->assertSeeText('Aguardando decisão')
        ->assertSeeText('Centro OBRAS')
        ->assertSeeText('À vista')
        ->assertSeeText('Avenida das Indústrias, 1500')
        ->assertSeeText('Enviado para aprovação interna')
        ->assertSeeText('Nível aprovado')
        ->assertSeeText('Sua decisão')
        ->assertSeeText('Comentário (obrigatório para rejeitar)')
        ->assertDontSeeText('Reatribuir aprovadores');
});

it('aprova pela tela, com comentário opcional, e mostra o resultado', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    ($this->as)($this->ana, fn () => Livewire::test(ApprovalShow::class, ['order' => $order->id])
        ->set('comment', str_repeat('a', 1001))
        ->call('approve')
        ->assertHasErrors(['comment'])
        ->assertSee('O comentário deve ter até 1.000 caracteres.')
        ->set('comment', '  De acordo com a obra  ')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSet('comment', '')
        ->assertSee('Pedido aprovado e confirmado com o distribuidor.')
        ->assertSee('Confirmado')
        ->assertDontSee('Sua decisão'));

    $decision = $this->scenario->system(fn (): OrderApprovalDecision => OrderApprovalDecision::query()->where('order_id', $order->id)->firstOrFail());

    expect(($this->stored)($order)->commercial_status)->toBe(CommercialStatus::Confirmed)
        ->and($decision->decision)->toBe(ApprovalDecision::Approved)
        ->and($decision->comment)->toBe('De acordo com a obra')
        ->and($decision->actor_membership_id)->toBe($this->ana->id);
});

it('exige o motivo para rejeitar e rejeita com motivo', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    ($this->as)($this->ana, fn () => Livewire::test(ApprovalShow::class, ['order' => $order->id])
        ->call('reject')
        ->assertHasErrors(['comment'])
        ->assertSee('Informe o motivo da rejeição (ao menos 5 caracteres).')
        ->set('comment', 'Fora do orçamento do trimestre')
        ->call('reject')
        ->assertHasNoErrors()
        ->assertSee('Pedido rejeitado. O orçamento reservado foi liberado.')
        ->assertSee('Rejeitou'));

    expect(($this->stored)($order)->commercial_status)->toBe(CommercialStatus::Rejected)
        ->and(($this->stored)($order)->approval_status)->toBe(ApprovalStatus::Rejected);
});

it('mostra a recusa da regra e o resultado quando a confirmação fica pendente', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $this->scenario->system(fn () => app(App\Services\Inventory\StockLedger::class)->adjust($this->scenario->warehouse->id, $this->scenario->sku->id, App\Domain\Shared\Quantity::of('-49'), 'Quebra no depósito'));

    ($this->as)($this->ana, function () use ($order): void {
        $component = Livewire::test(ApprovalShow::class, ['order' => $order->id]);
        $component->call('approve')
            ->assertSee('Pedido aprovado, mas ainda não confirmado com o distribuidor. Veja o motivo no pedido.')
            ->assertSee('Aprovado internamente, ainda não confirmado com o distribuidor')
            ->assertSee('Estoque insuficiente');

        // Segunda decisão no mesmo pedido: a regra recusa, sem erro de campo.
        $component->call('approve')
            ->assertHasNoErrors()
            ->assertSee('Este pedido não está aguardando aprovação.');
    });

    expect(($this->stored)($order)->commercial_status)->toBe(CommercialStatus::Approved);
});

it('decide como delegado vigente em nome do titular', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Inbox::delegate($this->scenario, $this->ana, $this->bruno);

    ($this->page)(route('portal.approvals', []), $this->bruno)
        ->assertOk()
        ->assertSeeText($order->number)
        ->assertSeeText('Em nome de '.Inbox::name($this->ana).' (delegação)');

    ($this->as)($this->bruno, fn () => Livewire::test(ApprovalShow::class, ['order' => $order->id])
        ->assertSee('Você decide em nome de')
        ->call('approve')
        ->assertSee('Pedido aprovado e confirmado com o distribuidor.')
        ->assertSee('em nome de '.Inbox::name($this->ana).' (delegação)'));

    $decision = $this->scenario->system(fn (): OrderApprovalDecision => OrderApprovalDecision::query()->where('order_id', $order->id)->firstOrFail());

    expect($decision->actor_membership_id)->toBe($this->bruno->id)
        ->and($decision->principal_membership_id)->toBe($this->ana->id)
        ->and($decision->delegation_id)->not->toBeNull();
});

it('reatribui pela tela os aprovadores do nível bloqueado', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Inbox::revoke($this->scenario, $this->ana);
    $outsider = $this->scenario->tenant->addBuyerUser($this->scenario->tenant->addBuyerCompany(), SystemRole::Approver);

    ($this->page)(route('portal.approvals.show', $order->id), $this->admin)
        ->assertOk()
        ->assertSeeText('Nível 1 bloqueado')
        ->assertSeeText('Sem acesso para aprovar')
        ->assertSeeText('Reatribuir aprovadores')
        ->assertSeeText(Inbox::name($this->bruno));

    ($this->as)($this->admin, fn () => Livewire::test(ApprovalShow::class, ['order' => $order->id])
        ->assertSet('approvers', [])
        ->call('reassign')
        ->assertHasErrors(['approvers'])
        ->set('approvers', [$outsider->id])
        ->set('reassignReason', 'Aprovadora desligada')
        ->call('reassign')
        ->assertHasErrors(['approvers'])
        ->assertSee('Escolha aprovadores ativos da empresa')
        ->set('approvers', [$this->bruno->id])
        ->set('reassignReason', 'ok')
        ->call('reassign')
        ->assertHasErrors(['reassignReason'])
        ->set('reassignReason', 'Aprovadora desligada da empresa')
        ->call('reassign')
        ->assertHasNoErrors()
        ->assertSee('Aprovadores do nível 1 reatribuídos.')
        ->assertDontSee('Nível 1 bloqueado'));

    $step = $this->scenario->system(fn (): OrderApprovalStep => OrderApprovalStep::query()->where('order_id', $order->id)->firstOrFail());

    expect($step->approver_membership_ids)->toBe([$this->bruno->id])
        ->and(Inbox::numbers($this->scenario, $this->bruno))->toBe([$order->number]);
});

it('recusa a reatribuição a quem não administra as regras', function (): void {
    Fx::rule($this->scenario, $this->company, [[2, [$this->ana, $this->bruno]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Inbox::revoke($this->scenario, $this->ana);

    ($this->page)(route('portal.approvals.show', $order->id), $this->bruno)
        ->assertOk()
        ->assertSeeText('Nível 1 bloqueado')
        ->assertSeeText('A administração da empresa precisa reatribuir os aprovadores deste nível.')
        ->assertDontSeeText('Reatribuir aprovadores');

    ($this->as)($this->bruno, fn () => Livewire::test(ApprovalShow::class, ['order' => $order->id])
        ->set('approvers', [$this->bruno->id])
        ->set('reassignReason', 'Tentativa sem permissão')
        ->call('reassign')
        ->assertForbidden());

    // A administração recebe pré-selecionados os aprovadores elegíveis que ainda não decidiram.
    ($this->as)($this->admin, fn () => Livewire::test(ApprovalShow::class, ['order' => $order->id])
        ->assertSet('approvers', [$this->bruno->id])
        ->assertSee('Reatribuir aprovadores'));

    $step = $this->scenario->system(fn (): OrderApprovalStep => OrderApprovalStep::query()->where('order_id', $order->id)->firstOrFail());
    expect($step->approver_membership_ids)->toEqualCanonicalizing([$this->ana->id, $this->bruno->id]);
});

it('trata identificadores adulterados como inexistentes', function (array $approvers): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    Inbox::revoke($this->scenario, $this->ana);

    ($this->as)($this->admin, fn () => Livewire::test(ApprovalShow::class, ['order' => $order->id])
        ->set('approvers', $approvers)
        ->set('reassignReason', 'Aprovadora desligada')
        ->call('reassign')
        ->assertNotFound());
})->with([
    'texto' => [['bruno']],
    'número' => [[42]],
    'lista aninhada' => [[['id' => 'x']]],
]);

it('protege o pedido da tela contra alteração pelo cliente', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $other = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '1', sameCompany: true));

    ($this->as)($this->ana, function () use ($order, $other): void {
        $component = Livewire::test(ApprovalShow::class, ['order' => $order->id]);

        expect(fn () => $component->set('orderId', $other->id))->toThrow(CannotUpdateLockedPropertyException::class)
            ->and(fn () => $component->set('message', 'Pedido aprovado'))->toThrow(CannotUpdateLockedPropertyException::class);
    });
});

it('responde 404 a pedido fora do alcance do aprovador ou malformado', function (string $case): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);

    $url = match ($case) {
        'outra empresa' => (function (): string {
            $other = $this->scenario->buyerWithCart(quantity: '1');
            Fx::rule($this->scenario, $other['company'], [[1, [$this->scenario->tenant->addBuyerUser($other['company'], SystemRole::Approver)]]]);

            return route('portal.approvals.show', Fx::place($this->scenario, $other)->id);
        })(),
        'outro tenant' => (function (): string {
            $foreign = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
            $buyer = $foreign->buyerWithCart(quantity: '1');
            Fx::rule($foreign, $buyer['company'], [[1, [$foreign->tenant->addBuyerUser($buyer['company'], SystemRole::Approver)]]]);

            return route('portal.approvals.show', Fx::place($foreign, $buyer)->id);
        })(),
        'sem participação' => route('portal.approvals.show', $order->id),
        'inexistente' => route('portal.approvals.show', (string) Str::uuid7()),
        'malformado' => url('/portal/aprovacoes/'.$order->number),
    };

    ($this->page)($url, $case === 'sem participação' ? $this->bruno : $this->ana)->assertNotFound();
})->with(['outra empresa', 'outro tenant', 'sem participação', 'inexistente', 'malformado']);

it('nega a fila e o detalhe sem portal.approvals e no contexto da equipe', function (): void {
    Fx::rule($this->scenario, $this->company, [[1, [$this->ana]]]);
    $order = Fx::place($this->scenario, $this->buyer);
    $staff = $this->scenario->tenant->addStaff(SystemRole::SalesManager);

    ($this->page)(route('portal.approvals'), $this->buyer['membership'])->assertForbidden();
    ($this->page)(route('portal.approvals.show', $order->id), $this->buyer['membership'])->assertForbidden();
    ($this->page)(route('portal.approvals'), $staff)->assertForbidden();
    ($this->page)(route('portal.approvals.show', $order->id), $staff)->assertForbidden();

    ($this->as)($this->buyer['membership'], fn () => Livewire::test(ApprovalsInbox::class)->assertForbidden());
});
