<?php

declare(strict_types=1);

use App\Actions\Quotes\System\ExpireDueQuotes;
use App\Domain\Shared\Quantity;
use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteStatus;
use App\Livewire\Portal\Ordering\CartPage;
use App\Livewire\Portal\Quotes\QuoteShow;
use App\Livewire\Portal\Quotes\QuotesIndex;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Ordering\Order;
use App\Models\Quotes\Quote;
use App\Support\Clock\Clock;
use App\Support\Clock\FrozenClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de cotação do portal (RF-040, RN-040, RN-043): solicitação pelo
| carrinho, lista em abas, proposta com estoque reservado ou consultado,
| aceite que gera o pedido, revisão, recusa e conversa.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->tz = (string) config('app.business_timezone');

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->buyer['membership'];
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get($url);
    };
    $this->as = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->buyer['membership'];
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
    $this->travel = fn (string $interval) => app()->instance(Clock::class, new FrozenClock(now()->add($interval)->toImmutable()));
});

it('solicita cotação pelo carrinho e leva ao detalhe', function (): void {
    ($this->as)(fn () => Livewire::test(CartPage::class)
        ->assertSee('Solicitar cotação')
        ->set('quoteMessage', 'Entrega parcelada na obra')
        ->call('requestQuote')
        ->assertHasNoErrors()
        ->assertRedirect());

    $quote = $this->scenario->system(fn (): Quote => Quote::query()->firstOrFail());

    expect($quote->status)->toBe(QuoteStatus::Requested);

    ($this->page)(route('portal.quotes.show', $quote->id))
        ->assertOk()
        ->assertSeeText($quote->number)
        ->assertSeeText('O distribuidor está preparando a proposta')
        ->assertSeeText('Entrega parcelada na obra');
});

it('lista em abas e mostra se a proposta reserva estoque', function (): void {
    $quote = F::request($this->scenario, $this->buyer);

    ($this->page)(route('portal.quotes', ['aba' => 'distributor']))->assertOk()->assertSeeText($quote->number)->assertSeeText('Aguardando proposta');

    F::submit($this->scenario, $this->manager, $quote, overrides: ['stockHoldUntilDay' => now($this->tz)->addDays(2)->toDateString()]);

    ($this->page)(route('portal.quotes'))->assertOk()->assertSeeText('Proposta recebida')->assertSeeText('Reservado até')->assertSeeText('R$ 300,00');
    ($this->page)(route('portal.quotes', ['aba' => 'inexistente', 'page' => 'x']))->assertOk();
});

it('distingue a reserva da proposta da disponibilidade consultada', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote, discount: '5');

    ($this->page)(route('portal.quotes.show', $quote->id))
        ->assertOk()
        ->assertSeeText('Esta proposta não reserva estoque')
        ->assertSeeText('Disponível agora')
        ->assertDontSeeText('Estoque reservado')
        ->assertDontSee('name="unit_price', escape: false)
        ->assertSeeText('R$ 95,00');

    $other = F::request($this->scenario, $this->scenario->buyerWithCart(quantity: '2', sameCompany: true));
    F::submit($this->scenario, $this->manager, $other, quantity: '2', overrides: ['stockHoldUntilDay' => now($this->tz)->addDay()->toDateString()]);

    ($this->page)(route('portal.quotes.show', $other->id))->assertOk()->assertSeeText('Estoque reservado para esta proposta até');
});

it('aceita pela tela e gera o pedido; sem permissão, orienta a pedir a um comprador', function (): void {
    // Solicitante: pede cotação, mas não faz pedidos.
    $requester = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->scenario->system(fn () => Membership::query()->whereKey($requester['membership']->id)
        ->update(['role_id' => $this->scenario->tenant->role(SystemRole::Requester)->id]));
    $quote = F::request($this->scenario, $requester);
    F::submit($this->scenario, $this->manager, $quote);

    $asRequester = $this->scenario->system(fn (): Membership => Membership::query()->with('role')->findOrFail($requester['membership']->id));

    ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])->assertSee('Peça a um comprador'), $asRequester);

    // Comprador com visão de todos os pedidos da empresa aceita.
    ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])
        ->assertSee('Aceitar e gerar pedido de R$ 300,00')
        ->set('shippingAddressId', $this->buyer['address']->id)
        ->call('accept')
        ->assertRedirect());

    expect($this->scenario->system(fn (): int => Order::query()->whereNotNull('source_quote_version_id')->count()))->toBe(1);
});

it('recusa o aceite feito sobre a versão anterior e mostra a nova', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote);
    $screen = ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])->set('shippingAddressId', $this->buyer['address']->id));
    F::submit($this->scenario, $this->manager, $quote, discount: '4');

    ($this->as)(fn () => $screen->call('accept')
        ->assertSee('O distribuidor enviou a versão 2')
        ->assertSee('Proposta vigente · versão 2'));

    expect($this->scenario->system(fn (): int => Order::query()->count()))->toBe(0);
});

it('pede revisão, recusa e comenta pela tela', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote);

    ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])
        ->set('comment', 'Conseguem entregar na sexta?')
        ->call('addComment')
        ->assertSee('Conseguem entregar na sexta?')
        ->set('counterMessage', 'Precisamos de 5 unidades')
        ->set('counterQuantities.1', '5')
        ->call('counter')
        ->assertSee('Pedido de revisão enviado')
        ->assertSee('Pedido de revisão'));

    expect(F::fresh($this->scenario, $quote)->status)->toBe(QuoteStatus::Requested);

    F::submit($this->scenario, $this->manager, $quote, quantity: '5');

    ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])
        ->set('closeReasonCode', 'price')
        ->call('reject')
        ->assertSee('Proposta recusada'));

    expect(F::fresh($this->scenario, $quote)->status)->toBe(QuoteStatus::Rejected);
});

it('protege a versão exibida contra alteração e esconde cotação de outra empresa', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    $outsider = $this->scenario->buyerWithCart(quantity: '1');

    expect(fn () => ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])->set('reviewedVersionId', 'x')))->toThrow(CannotUpdateLockedPropertyException::class);

    ($this->page)(route('portal.quotes.show', $quote->id), $outsider['membership'])->assertNotFound();
    ($this->as)(fn () => Livewire::test(QuotesIndex::class)->assertDontSee($quote->number), $outsider['membership']);
});

it('mostra a última proposta da cotação vencida e pede revalidação', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote, overrides: ['validUntilDay' => now($this->tz)->toDateString()]);
    ($this->travel)('P1D');
    app(ExpireDueQuotes::class)->handle();

    expect(F::fresh($this->scenario, $quote)->status)->toBe(QuoteStatus::Expired);

    ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])
        ->assertSee('Última proposta · versão 1')
        ->assertSee('Vencida em')
        ->assertSee('Pedir revalidação')
        ->assertDontSee('Aceitar e gerar pedido')
        ->assertSet('counterQuantities.1', Quantity::of('3')->format())
        ->set('counterMessage', 'Podem revalidar o mesmo preço?')
        ->call('counter')
        ->assertHasNoErrors()
        ->assertSee('Pedido de revisão enviado'));

    expect(F::fresh($this->scenario, $quote)->status)->toBe(QuoteStatus::Requested);
});

it('refaz as quantidades do pedido de revisão quando chega outra versão', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote);
    $screen = ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])->set('counterQuantities.1', '7'));
    F::submit($this->scenario, $this->manager, $quote, quantity: '5');

    ($this->as)(fn () => $screen->call('$refresh')
        ->assertSee('Proposta vigente · versão 2')
        ->assertSet('counterQuantities.1', Quantity::of('5')->format()));
});

it('recusa a proposta mesmo que a reserva tenha terminado depois da exibição', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote, overrides: ['stockHoldUntilDay' => now($this->tz)->toDateString()]);
    $screen = ($this->as)(fn () => Livewire::test(QuoteShow::class, ['quote' => $quote->id])
        ->assertSee('Estoque reservado para esta proposta até')
        ->set('closeReasonCode', 'price'));
    ($this->travel)('P1D');
    app(ExpireDueQuotes::class)->handle();

    // A recusa parte da tela exibida com a reserva ativa.
    ($this->as)(fn () => $screen->call('reject')->assertSee('Proposta recusada'));

    expect(F::fresh($this->scenario, $quote)->status)->toBe(QuoteStatus::Rejected);
});

it('usa a mesma chave da solicitação de cotação aberta no carrinho', function (): void {
    $key = ($this->as)(function (): string {
        $screen = Livewire::test(CartPage::class);
        $key = $screen->get('quoteKey');

        expect($key)->toBeString()->not->toBe('');

        $screen->set('quoteMessage', 'Entrega na obra')->call('requestQuote')->assertHasNoErrors();

        return $key;
    });

    expect($this->scenario->system(fn (): mixed => DB::table('idempotency_keys')->where('scope', 'quote.request')->value('key')))->toBe($key);
});

it('liga a cotação de origem no pedido só para quem pode abri-la', function (): void {
    $quote = F::request($this->scenario, $this->buyer);
    F::submit($this->scenario, $this->manager, $quote);
    $order = F::accept($this->scenario, $this->buyer, $quote);
    $approver = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);
    $link = 'href="'.route('portal.quotes.show', $quote->id).'"';

    ($this->page)(route('portal.orders.show', $order->id))->assertOk()->assertSee($link, escape: false);
    ($this->page)(route('portal.orders.show', $order->id), $approver)->assertOk()->assertSeeText(F::fresh($this->scenario, $quote)->number)->assertDontSee($link, escape: false);
});
