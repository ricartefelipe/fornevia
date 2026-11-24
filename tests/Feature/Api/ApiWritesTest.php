<?php

declare(strict_types=1);

use App\Actions\Integrations\ManageApiClients;
use App\Enums\Ordering\CommercialStatus;
use App\Models\Buyers\BuyerCompany;
use App\Models\Inventory\StockLevel;
use App\Models\Ordering\Order;
use App\Models\Ordering\OrderItem;
use Database\Factories\Support\BrazilianDocuments;
use Tests\Integration\Approvals\ApprovalFixtures as Fx;
use Tests\Support\Builders\OrderingScenario;

/*
| Escrita da API v1: cadastro de comprador, entrada e ajuste de estoque e
| cancelamento de pedido, cada um com a permissão própria do cliente e
| Idempotency-Key (repetição devolve a mesma resposta sem repetir o efeito).
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->owner = $this->scenario->tenant->ownerMembership;
    $this->token = fn (array $permissions): string => $this->scenario->tenant->actingAs($this->owner, static fn (): array => app(ManageApiClients::class)->create('ERP', $permissions, 30))[1];
    $this->write = fn (string $token, string $uri, array $data, ?string $key = 'chave-0001') => $this->withHeaders(array_filter([
        'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => $key,
    ]))->postJson('/api/v1'.$uri, $data);
    $this->onHand = fn (): string => $this->scenario->system(fn (): string => StockLevel::query()->where('sku_id', $this->scenario->sku->id)->sole()->on_hand->toDatabase());
});

it('cadastra o comprador uma única vez por chave e recusa a chave com outro conteúdo', function (): void {
    $token = ($this->token)(['buyers.manage', 'buyers.view']);
    $document = BrazilianDocuments::cnpj();
    $data = ['legal_name' => 'Construtora Serra Azul Ltda', 'trade_name' => 'Serra Azul', 'document' => $document, 'email' => 'compras@serraazul.example'];

    $first = ($this->write)($token, '/buyers', $data)->assertCreated()->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('data.document', $document)->assertJsonPath('data.status', 'active');
    ($this->write)($token, '/buyers', $data)->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $first->json('data.id'));
    ($this->write)($token, '/buyers', [...$data, 'trade_name' => 'Outro nome'])->assertStatus(409);

    expect($this->scenario->system(static fn (): int => BuyerCompany::query()->where('document', $document)->count()))->toBe(1);
});

it('valida campos, chave e regras do cadastro', function (): void {
    $token = ($this->token)(['buyers.manage']);

    ($this->write)($token, '/buyers', ['legal_name' => 'A Ltda', 'trade_name' => 'A', 'document' => BrazilianDocuments::cnpj()], null)
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['Idempotency-Key']]]);
    ($this->write)($token, '/buyers', ['legal_name' => 'A Ltda', 'trade_name' => 'A', 'document' => '123', 'cor' => 'azul'])
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['document', 'cor']]]);
    ($this->write)($token, '/buyers', ['legal_name' => 'A Ltda', 'trade_name' => 'A', 'document' => BrazilianDocuments::cnpj()], 'curta')
        ->assertUnprocessable();
});

it('exige a permissão de escrita de cada recurso', function (): void {
    $token = ($this->token)(['buyers.view', 'inventory.view', 'orders.view']);

    ($this->write)($token, '/buyers', ['legal_name' => 'A Ltda', 'trade_name' => 'A', 'document' => BrazilianDocuments::cnpj()])->assertForbidden();
    ($this->write)($token, '/stock/receipts', ['warehouse_code' => 'CD1', 'sku_code' => $this->scenario->sku->code, 'quantity' => '5'])->assertForbidden();
    ($this->write)($token, '/stock/adjustments', ['warehouse_code' => 'CD1', 'sku_code' => $this->scenario->sku->code, 'delta' => '-1', 'reason' => 'Inventário'])->assertForbidden();
    ($this->write)($token, '/orders/'.Illuminate\Support\Str::uuid7().'/cancellation', ['reason' => 'Pedido duplicado'])->assertForbidden();
});

it('registra entrada e ajuste de estoque sem repetir o efeito', function (): void {
    $token = ($this->token)(['inventory.manage']);
    $sku = $this->scenario->sku->code;

    ($this->write)($token, '/stock/receipts', ['warehouse_code' => 'cd1', 'sku_code' => strtolower($sku), 'quantity' => '5', 'note' => 'NF 123'])
        ->assertCreated()->assertJsonPath('data.on_hand', '15.000');
    ($this->write)($token, '/stock/receipts', ['warehouse_code' => 'cd1', 'sku_code' => strtolower($sku), 'quantity' => '5', 'note' => 'NF 123'])
        ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    ($this->write)($token, '/stock/adjustments', ['warehouse_code' => 'CD1', 'sku_code' => $sku, 'delta' => '-3', 'reason' => 'Inventário mensal'], 'ajuste-0001')
        ->assertCreated()->assertJsonPath('data.on_hand', '12.000')->assertJsonPath('data.available', '12.000');

    expect(($this->onHand)())->toBe('12.000');

    ($this->write)($token, '/stock/receipts', ['warehouse_code' => 'XX', 'sku_code' => 'NAO', 'quantity' => '0'], 'entrada-02')
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['warehouse_code', 'sku_code', 'quantity']]]);
    // Saída acima do disponível: conflito com o saldo (409), sem efeito.
    ($this->write)($token, '/stock/adjustments', ['warehouse_code' => 'CD1', 'sku_code' => $sku, 'delta' => '-100', 'reason' => 'Inventário mensal'], 'ajuste-0002')
        ->assertStatus(409);
    expect(($this->onHand)())->toBe('12.000');
});

it('cancela parte do pedido e depois o restante, liberando a reserva', function (): void {
    $token = ($this->token)(['orders.manage', 'orders.view']);
    $order = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '4'));
    $item = $this->scenario->system(static fn (): OrderItem => OrderItem::query()->where('order_id', $order->id)->sole());

    ($this->write)($token, "/orders/{$order->id}/cancellation", ['reason' => 'Cliente reduziu a compra', 'items' => [['id' => $item->id, 'quantity' => '1']]], 'cancela-001')
        ->assertOk()->assertJsonPath('data.items.0.quantity_cancelled', '1.000')->assertJsonPath('data.commercial_status', 'partially_cancelled');
    ($this->write)($token, "/orders/{$order->id}/cancellation", ['reason' => 'Cliente reduziu a compra', 'items' => [['id' => $item->id, 'quantity' => '1']]], 'cancela-001')
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.items.0.quantity_cancelled', '1.000');
    ($this->write)($token, "/orders/{$order->id}/cancellation", ['reason' => 'Cliente desistiu da compra'], 'cancela-002')
        ->assertOk()->assertJsonPath('data.commercial_status', 'cancelled');

    expect($this->scenario->system(static fn (): CommercialStatus => Order::query()->findOrFail($order->id)->commercial_status))->toBe(CommercialStatus::Cancelled)
        ->and($this->scenario->system(fn (): string => StockLevel::query()->where('sku_id', $this->scenario->sku->id)->sole()->reserved->toDatabase()))->toBe('0.000');

    ($this->write)($token, "/orders/{$order->id}/cancellation", ['reason' => 'abc', 'items' => [['id' => 'x', 'quantity' => 2]]], 'cancela-003')
        ->assertUnprocessable()->assertJsonStructure(['error' => ['fields' => ['reason', 'items.0']]]);
    ($this->write)($token, '/orders/'.Illuminate\Support\Str::uuid7().'/cancellation', ['reason' => 'Pedido duplicado'], 'cancela-004')->assertNotFound();
});

it('registra o cliente da API como autor do cancelamento na auditoria', function (): void {
    $token = ($this->token)(['orders.manage', 'orders.view']);
    $order = Fx::place($this->scenario, $this->scenario->buyerWithCart(quantity: '2'));

    ($this->write)($token, "/orders/{$order->id}/cancellation", ['reason' => 'Cliente desistiu da compra'], 'cancela-100')->assertOk();

    $audit = $this->scenario->system(static fn () => App\Models\Audit\AuditLog::query()->where('subject_id', $order->id)->where('action', 'like', 'order.%cancel%')->latest('created_at')->first());
    expect($audit)->not->toBeNull()->and($audit->actor_api_client_id)->not->toBeNull()->and($audit->actor_user_id)->toBeNull();
});
