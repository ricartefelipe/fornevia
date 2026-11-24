<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use Laravel\Dusk\Browser;
use Tests\Support\Builders\OrderingScenario;

/*
| Fluxo de compra no navegador, com JavaScript real: login, retomada do
| contexto, quantidade validada ao digitar, carrinho, confirmação do pedido
| e a lista de pedidos da equipe filtrando ao vivo.
*/

const BROWSER_PASSWORD = 'senha-segura-123';

function browserLogin(Browser $browser, User $user): Browser
{
    return $browser->visit('/login')
        ->type('email', $user->email)
        ->type('password', BROWSER_PASSWORD)
        ->press('Entrar');
}

it('recusa senha errada sem revelar se o e-mail existe', function (): void {
    $scenario = OrderingScenario::create();
    $user = $scenario->tenant->owner;

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->visit('/login')->type('email', $user->email)->type('password', 'senha-incorreta')->press('Entrar')
            ->waitForText('Credenciais inválidas.')
            ->assertPathIs('/login')
            ->assertInputValue('password', '');
    });
});

it('o comprador ajusta a quantidade no catálogo e confirma o pedido pelo carrinho', function (): void {
    $scenario = OrderingScenario::create(stock: '50', unitPriceCents: 2_500);
    $buyer = $scenario->buyerWithCart(quantity: '2');
    $user = User::query()->findOrFail($buyer['membership']->user_id);
    $sku = $scenario->sku;

    $this->browse(function (Browser $browser) use ($user, $sku): void {
        browserLogin($browser, $user)->waitForLocation('/portal')
            ->visit('/portal/catalogo/'.$sku->product_id)
            ->waitFor('#quantidade-'.$sku->id)
            ->type('#quantidade-'.$sku->id, '3')
            ->waitForText('Total para 3')
            ->press('Adicionar ao carrinho')
            ->waitForText('carrinho')
            ->visit('/portal/carrinho')
            ->waitForText('Confirmar pedido')
            // 2 já no carrinho + 3 adicionadas pelo catálogo, a R$ 25,00.
            ->assertSee($sku->code)
            ->assertSee('R$ 125,00')
            ->press('Confirmar pedido')
            ->waitUntil("window.location.pathname.endsWith('/confirmado')", 20);
    });

    $order = $scenario->system(fn () => Order::query()->sole());
    expect($order->placed_by_user_id)->toBe($user->id)
        ->and($order->total_cents->toDecimalString())->toBe('125.00');

    $this->browse(fn (Browser $browser) => $browser->assertPathIs('/portal/pedidos/'.$order->id.'/confirmado')->assertSee($order->number));
});

it('a equipe filtra a lista de pedidos enquanto digita', function (): void {
    $scenario = OrderingScenario::create(stock: '50', unitPriceCents: 1_000);
    Tests\Integration\Approvals\ApprovalFixtures::place($scenario, $scenario->buyerWithCart(quantity: '1'));
    Tests\Integration\Approvals\ApprovalFixtures::place($scenario, $scenario->buyerWithCart(quantity: '2'));
    $numbers = $scenario->system(fn () => Order::query()->orderBy('number')->pluck('number')->all());
    $rep = $scenario->tenant->addStaff(SystemRole::SalesManager);
    $user = User::query()->findOrFail($rep->user_id);

    $this->browse(function (Browser $browser) use ($user, $numbers): void {
        browserLogin($browser, $user)->waitForLocation('/painel')
            ->visit('/pedidos')
            ->waitForText($numbers[0])
            ->assertSee($numbers[1])
            ->type('search', $numbers[1])
            ->waitUntilMissingText($numbers[0])
            ->assertSee($numbers[1])
            ->assertSee('1 pedido encontrado.');
    });
});
