<?php

declare(strict_types=1);

use App\Actions\Quotes\AddQuoteComment;
use App\Actions\Quotes\Portal\RequestQuote;
use App\Data\Quotes\RequestQuoteData;
use App\Domain\Ordering\Exceptions\CartNotReady;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteNotOpen;
use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteStatus;
use App\Models\Quotes\Quote;
use App\Models\Quotes\QuoteComment;
use App\Models\Quotes\QuoteRequestItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Builders\OrderingScenario;

/*
| Solicitação de cotação a partir do carrinho (RF-040) e comentários.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '1', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->request = fn (array $buyer, array $overrides = []): Quote => $this->scenario->tenant->actingAs($buyer['membership'], fn (): Quote => app(RequestQuote::class)->handle(new RequestQuoteData(...[
        'cartId' => $buyer['cart']->id, 'idempotencyKey' => (string) Str::uuid7(), ...$overrides,
    ])));
});

it('cria a cotação a partir do carrinho, com retrato dos itens, sem preço e sem exigir estoque', function (): void {
    $quote = ($this->request)($this->buyer, ['message' => '  Precisamos de entrega na obra  ', 'neededBy' => now('America/Sao_Paulo')->addDays(10)->toDateString()]);

    $stored = $this->scenario->system(fn (): array => [
        Quote::query()->findOrFail($quote->id),
        QuoteRequestItem::query()->get(),
        DB::table('quote_requests')->value('message'),
        DB::table('cart_items')->where('cart_id', $this->buyer['cart']->id)->count(),
        DB::table('outbox_messages')->where('type', 'quote.requested')->count(),
        DB::table('audit_logs')->where('action', 'quote.requested')->value('actor_membership_id'),
    ]);

    expect($stored[0]->number)->toBe('COT-0000001')
        ->and($stored[0]->status)->toBe(QuoteStatus::Requested)
        ->and($stored[0]->requested_by_membership_id)->toBe($this->buyer['membership']->id)
        ->and($stored[1])->toHaveCount(1)
        ->and($stored[1][0]->sku_code)->toBe($this->scenario->sku->code)
        ->and($stored[1][0]->quantity->toDatabase())->toBe('3.000')
        ->and($stored[2])->toBe('Precisamos de entrega na obra')
        ->and($stored[3])->toBe(0)
        ->and($stored[4])->toBe(1)
        ->and($stored[5])->toBe($this->buyer['membership']->id);
});

it('numera por distribuidor e mantém os itens no carrinho quando pedido', function (): void {
    $first = ($this->request)($this->buyer, ['removeFromCart' => false]);
    $other = $this->scenario->buyerWithCart(quantity: '1', sameCompany: true);
    $second = ($this->request)($other);
    $otherTenant = OrderingScenario::create(stock: '1');
    $foreign = $otherTenant->tenant->actingAs(($buyer = $otherTenant->buyerWithCart(quantity: '1'))['membership'], fn (): Quote => app(RequestQuote::class)->handle(new RequestQuoteData($buyer['cart']->id, (string) Str::uuid7())));

    expect([$first->number, $second->number, $foreign->number])->toBe(['COT-0000001', 'COT-0000002', 'COT-0000001'])
        ->and($this->scenario->system(fn (): int => DB::table('cart_items')->where('cart_id', $this->buyer['cart']->id)->count()))->toBe(1);
});

it('repete o resultado com a mesma chave de idempotência', function (): void {
    $key = (string) Str::uuid7();
    $first = ($this->request)($this->buyer, ['idempotencyKey' => $key, 'removeFromCart' => false]);
    $again = ($this->request)($this->buyer, ['idempotencyKey' => $key, 'removeFromCart' => false]);

    expect($again->id)->toBe($first->id)
        ->and($this->scenario->system(fn (): int => Quote::query()->count()))->toBe(1);
});

it('recusa item inativo, carrinho vazio, mensagem longa e data inválida', function (): void {
    $this->scenario->system(fn () => DB::table('skus')->where('id', $this->scenario->sku->id)->update(['status' => 'inactive']));

    expect(fn () => ($this->request)($this->buyer))->toThrow(CartNotReady::class);

    $this->scenario->system(fn () => DB::table('skus')->where('id', $this->scenario->sku->id)->update(['status' => 'active']));

    expect(fn () => ($this->request)($this->buyer, ['message' => str_repeat('a', 2001)]))->toThrow(InvalidQuote::class, '2.000 caracteres')
        ->and(fn () => ($this->request)($this->buyer, ['neededBy' => '2020-01-01']))->toThrow(InvalidQuote::class, 'data de necessidade');

    $this->scenario->system(fn () => DB::table('cart_items')->delete());

    expect(fn () => ($this->request)($this->buyer))->toThrow(CartNotReady::class, 'Adicione itens');
});

it('exige permissão de solicitar cotações', function (): void {
    $approver = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Approver);

    expect(fn () => $this->scenario->tenant->actingAs($approver, fn () => app(RequestQuote::class)->handle(new RequestQuoteData($this->buyer['cart']->id, (string) Str::uuid7()))))
        ->toThrow(AuthorizationException::class);
});

it('registra comentários compartilhados e notas internas, só em cotação aberta', function (): void {
    $quote = ($this->request)($this->buyer);

    $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(AddQuoteComment::class)->forBuyer($quote->id, 'Podem incluir o frete?'));
    $this->scenario->tenant->actingAs($this->scenario->tenant->ownerMembership, fn () => app(AddQuoteComment::class)->forStaff($quote->id, 'Margem apertada neste cliente', internal: true));
    $this->scenario->tenant->actingAs($this->scenario->tenant->ownerMembership, fn () => app(AddQuoteComment::class)->forStaff($quote->id, 'Frete incluso acima de R$ 500', internal: false));

    $seenByBuyer = $this->scenario->tenant->actingAs($this->buyer['membership'], static fn (): array => QuoteComment::query()->orderBy('created_at')->orderBy('id')->pluck('body')->all());

    expect($seenByBuyer)->toBe(['Podem incluir o frete?', 'Frete incluso acima de R$ 500'])
        ->and($this->scenario->system(fn (): int => QuoteComment::query()->count()))->toBe(3)
        ->and(fn () => $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(AddQuoteComment::class)->forBuyer($quote->id, '   ')))->toThrow(InvalidQuote::class);

    $this->scenario->system(fn () => DB::table('quotes')->where('id', $quote->id)->update(['status' => 'cancelled', 'closed_at' => now(), 'close_reason_code' => 'not_needed']));

    expect(fn () => $this->scenario->tenant->actingAs($this->buyer['membership'], fn () => app(AddQuoteComment::class)->forBuyer($quote->id, 'Ainda aqui?')))->toThrow(QuoteNotOpen::class);
});

it('não deixa comentar cotação de outra empresa nem de outro solicitante sem visão de todas', function (): void {
    $quote = ($this->request)($this->buyer);
    $requester = $this->scenario->tenant->addBuyerUser($this->buyer['company'], SystemRole::Requester);
    $outsider = $this->scenario->tenant->addBuyerUser($this->scenario->tenant->addBuyerCompany(['trade_name' => 'Construtora Vale Verde']), SystemRole::BuyerAdmin);

    expect(fn () => $this->scenario->tenant->actingAs($requester, fn () => app(AddQuoteComment::class)->forBuyer($quote->id, 'Olá')))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => $this->scenario->tenant->actingAs($outsider, fn () => app(AddQuoteComment::class)->forBuyer($quote->id, 'Olá')))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});
