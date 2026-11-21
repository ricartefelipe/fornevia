<?php

declare(strict_types=1);

use App\Domain\Shared\Cnpj;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Orders\OrdersIndex;
use App\Models\Ordering\Order;
use Livewire\Livewire;
use Tests\Integration\Ordering\Staff\StaffOrderFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Lista de pedidos da equipe: filtros pelos estados separados, empresa,
| período no fuso comercial e busca por número ou ordem de compra;
| paginação com totais por página e recorte da carteira do representante.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '500', unitPriceCents: 1_000);
    $this->tenant = $this->scenario->tenant;
    $this->manager = $this->tenant->addStaff(SystemRole::SalesManager);

    // Serra Azul: dois pedidos; Bom Preço: um pedido com estados avançados.
    $first = Fx::place($this->scenario, '3', ['purchaseOrderReference' => 'OC-7781'], sameCompany: true);
    $this->serra = Fx::rename($this->tenant, $first['company'], 'Construtora Serra Azul');
    $second = Fx::place($this->scenario, '2', ['purchaseOrderReference' => 'OC-9001']);
    $this->bomPreco = Fx::rename($this->tenant, $second['company'], 'Mercado Bom Preço');
    $third = Fx::place($this->scenario, '5', sameCompany: true);

    $this->a = Fx::update($this->tenant, $first['order'], ['created_at' => '2026-11-03T13:00:00Z']);
    $this->b = Fx::update($this->tenant, $second['order'], [
        'created_at' => '2026-11-10T14:00:00Z',
        'approval_status' => 'approved',
        'fulfillment_status' => 'partially_shipped',
        'financial_status' => 'invoiced',
    ]);
    // 02:30 UTC de 10/11 ainda é 09/11 no fuso comercial (UTC−3).
    $this->c = Fx::update($this->tenant, $third['order'], ['created_at' => '2026-11-10T02:30:00Z', 'commercial_status' => 'cancelled']);

    $this->screen = fn (array $query = []) => Livewire::withQueryParams($query)->test(OrdersIndex::class);
    $this->as = fn ($member, Closure $callback) => Fx::asMember($this->tenant, $member ?? $this->manager, $callback);
});

it('lista do mais recente ao mais antigo com os quatro estados e o total da página', function (): void {
    ($this->as)(null, fn () => ($this->screen)()
        ->assertSeeInOrder([$this->b->number, $this->c->number, $this->a->number])
        ->assertSee('Construtora Serra Azul')
        ->assertSee('Mercado Bom Preço')
        ->assertSee('OC-7781')
        ->assertSee('Aprovada')
        ->assertSee('Expedido parcialmente')
        ->assertSee('Faturado')
        ->assertSee('Cancelado')
        ->assertSee('3 pedidos encontrados.')
        ->assertSee('Total desta página (3 pedidos)')
        ->assertSee('R$ 100,00'));
});

it('filtra pelos estados comercial, de aprovação, de atendimento e financeiro', function (string $property, string $value, array $expected): void {
    $numbers = ['a' => $this->a->number, 'b' => $this->b->number, 'c' => $this->c->number];

    ($this->as)(null, function () use ($property, $value, $expected, $numbers): void {
        $component = ($this->screen)()->set($property, $value);

        foreach ($numbers as $key => $number) {
            in_array($key, $expected, true) ? $component->assertSee($number) : $component->assertDontSee($number);
        }
    });
})->with([
    'comercial cancelado' => ['commercialStatus', 'cancelled', ['c']],
    'comercial confirmado' => ['commercialStatus', 'confirmed', ['a', 'b']],
    'aprovação aprovada' => ['approvalStatus', 'approved', ['b']],
    'aprovação dispensada' => ['approvalStatus', 'not_required', ['a', 'c']],
    'atendimento parcial' => ['fulfillmentStatus', 'partially_shipped', ['b']],
    'atendimento pendente' => ['fulfillmentStatus', 'pending', ['a', 'c']],
    'financeiro faturado' => ['financialStatus', 'invoiced', ['b']],
    'valor inexistente é ignorado' => ['financialStatus', 'estornado', ['a', 'b', 'c']],
]);

it('lê os filtros da URL', function (): void {
    ($this->as)(null, fn () => ($this->screen)(['situacao' => 'cancelled', 'busca' => $this->c->number])
        ->assertSet('commercialStatus', 'cancelled')
        ->assertSet('search', $this->c->number)
        ->assertSee($this->c->number)
        ->assertDontSee($this->a->number)
        ->assertDontSee($this->b->number));
});

it('filtra por empresa compradora e busca empresas para o filtro', function (): void {
    $foreign = Fx::place(OrderingScenario::create(), '1');

    $option = static fn ($company): string => $company->trade_name.' · '.Cnpj::format($company->document);

    ($this->as)(null, fn () => ($this->screen)()
        ->assertSee($option($this->serra))
        ->assertSee($option($this->bomPreco))
        ->assertDontSee($option($foreign['company']))
        ->set('companySearch', 'serra')
        ->assertSee($option($this->serra))
        ->assertDontSee($option($this->bomPreco))
        ->set('companySearch', Cnpj::format($this->bomPreco->document))
        ->assertSee($option($this->bomPreco))
        ->assertDontSee($option($this->serra))
        ->set('companySearch', '')
        ->set('company', $this->serra->id)
        ->assertSee($this->a->number)
        ->assertSee($this->c->number)
        ->assertDontSee($this->b->number)
        ->set('company', strtoupper($this->bomPreco->id))
        ->assertSee($this->b->number)
        ->assertDontSee($this->a->number)
        ->set('company', 'empresa-1')
        ->assertSee($this->a->number)
        ->assertSee($this->b->number)
        ->set('company', $foreign['company']->id)
        ->assertSee('Nenhum pedido encontrado')
        ->assertDontSee($foreign['order']->number));
});

it('filtra pelo período da data do pedido no fuso comercial', function (): void {
    ($this->as)(null, fn () => ($this->screen)()
        ->set('dateFrom', '2026-11-10')
        ->assertHasNoErrors()
        ->assertSee($this->b->number)
        ->assertDontSee($this->c->number)
        ->assertDontSee($this->a->number)
        ->set('dateFrom', '2026-11-09')
        ->set('dateUntil', '2026-11-09')
        ->assertSee($this->c->number)
        ->assertDontSee($this->b->number)
        ->set('dateFrom', '')
        ->set('dateUntil', '2026-11-05')
        ->assertSee($this->a->number)
        ->assertDontSee($this->c->number));
});

it('aponta e ignora datas inválidas no período', function (): void {
    ($this->as)(null, fn () => ($this->screen)()
        ->set('dateFrom', '2026-02-30')
        ->assertHasErrors(['dateFrom'])
        ->assertSee($this->a->number)
        ->set('dateFrom', '2026-11-10')
        ->set('dateUntil', '2026-11-01')
        ->assertHasErrors(['dateUntil' => 'after_or_equal'])
        ->set('dateUntil', '1999-12-31')
        ->assertHasErrors(['dateUntil']));
});

it('busca pelo número do pedido ou pela ordem de compra', function (): void {
    ($this->as)(null, fn () => ($this->screen)()
        ->set('search', 'oc-9001')
        ->assertSee($this->b->number)
        ->assertDontSee($this->a->number)
        ->set('search', substr($this->a->number, -4))
        ->assertSee($this->a->number)
        ->assertDontSee($this->b->number)
        ->set('search', '%')
        ->assertSee('Nenhum pedido encontrado')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSee('3 pedidos encontrados.'));
});

it('pagina 25 pedidos por vez com o total de cada página', function (): void {
    $this->tenant->system(function (): void {
        foreach (range(1, 23) as $n) {
            $copy = Order::query()->findOrFail($this->a->id)->replicate();
            $copy->forceFill([
                'number' => 'PED-9'.str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                'source_cart_id' => null,
                'created_at' => '2026-10-01T12:00:00Z',
            ])->save();
        }
    });

    ($this->as)(null, fn () => ($this->screen)()
        ->assertSee('26 pedidos encontrados.')
        ->assertSee('Total desta página (25 pedidos)')
        ->assertSee('R$ 760,00')
        ->call('nextPage')
        ->assertSee('Total desta página (1 pedido)')
        ->assertSee('R$ 30,00')
        ->set('commercialStatus', 'cancelled')
        ->assertSet('paginators.page', 1)
        ->assertSee('Total desta página (1 pedido)'));
});

it('restringe o representante aos pedidos e empresas da carteira', function (): void {
    $rep = $this->tenant->addStaff(SystemRole::SalesRep);
    Fx::assignRep($this->tenant, $this->serra, $rep);

    ($this->as)($rep, fn () => ($this->screen)()
        ->assertSee('Você vê os pedidos das empresas da sua carteira.')
        ->assertSee($this->a->number)
        ->assertSee($this->c->number)
        ->assertDontSee($this->b->number)
        ->assertDontSee('Mercado Bom Preço')
        ->set('company', $this->bomPreco->id)
        ->assertSee('Nenhum pedido encontrado')
        ->set('search', $this->b->number)
        ->assertDontSee($this->b->number));
});

it('mostra estado vazio sem pedidos no distribuidor', function (): void {
    $empty = OrderingScenario::create();
    $manager = $empty->tenant->addStaff(SystemRole::SalesManager);

    Fx::asMember($empty->tenant, $manager, static fn () => Livewire::test(OrdersIndex::class)
        ->assertSee('Nenhum pedido registrado')
        ->assertDontSee('Total desta página'));
});
