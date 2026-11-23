<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteVersionStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Integration\Quotes\QuoteFixtures as Q;
use Tests\Support\Builders\OrderingScenario;

/*
| Proteções das cotações no banco (ADR-024, ADR-025): somente leitura no
| contexto de comprador, versões e itens não enviados e dados internos fora
| do alcance do comprador, conteúdo imutável, transições e totais
| conferidos, aprovação comercial antes do envio e chaves por empresa.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '50', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->company = $this->buyer['company'];
    $this->tenantId = $this->scenario->tenant->organization->id;
    $this->quote = Q::quote($this->scenario, $this->buyer['membership']);
    $this->asBuyer = fn (Closure $callback): mixed => $this->scenario->tenant->actingAs($this->buyer['membership'], $callback);
    $this->system = fn (Closure $callback): mixed => $this->scenario->system($callback);
});

it('não deixa o comprador gravar nas tabelas de cotação pelo banco', function (): void {
    $version = Q::send($this->scenario, Q::version($this->scenario, $this->quote));

    $updated = ($this->asBuyer)(fn (): array => [
        DB::table('quotes')->where('id', $this->quote->id)->update(['status' => 'cancelled', 'closed_at' => now(), 'close_reason_code' => 'price']),
        DB::table('quote_versions')->where('id', $version->id)->update(['status' => 'rejected', 'closed_at' => now()]),
    ]);

    expect($updated)->toBe([0, 0])
        ->and(fn () => ($this->asBuyer)(fn () => DB::table('quotes')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'number' => 'COT-FORJADA', 'buyer_company_id' => $this->company->id,
            'requested_by_user_id' => $this->buyer['membership']->user_id, 'requested_by_membership_id' => $this->buyer['membership']->id,
            'status' => 'requested', 'last_activity_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => ($this->asBuyer)(fn () => DB::table('quote_comments')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id, 'quote_id' => $this->quote->id,
            'author_user_id' => $this->buyer['membership']->user_id, 'buyer_membership_id' => $this->buyer['membership']->id,
            'author_name' => 'Comprador', 'visibility' => 'shared', 'body' => 'Comentário direto', 'created_at' => now(),
        ])))->toThrow(QueryException::class, 'row-level security')
        ->and(fn () => ($this->asBuyer)(fn () => DB::table('quote_items')->where('quote_version_id', $version->id)->delete()))->toThrow(QueryException::class, 'permission denied');
});

it('esconde do comprador versões não enviadas, a avaliação comercial e as notas internas', function (): void {
    $sent = Q::send($this->scenario, Q::version($this->scenario, $this->quote));
    Q::version($this->scenario, $this->quote, ['status' => QuoteVersionStatus::PendingApproval, 'requires_approval' => true, 'discount_bps' => 1_500]);
    ($this->system)(fn () => DB::table('quote_comments')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id, 'quote_id' => $this->quote->id,
        'author_user_id' => $this->scenario->tenant->owner->id, 'staff_membership_id' => $this->scenario->tenant->ownerMembership->id,
        'author_name' => 'Equipe', 'visibility' => 'internal', 'body' => 'Margem apertada', 'created_at' => now(),
    ]));

    $seen = ($this->asBuyer)(fn (): array => [
        DB::table('quote_versions')->pluck('id')->all(),
        DB::table('quote_items')->count(),
        DB::table('quote_commercial_reviews')->count(),
        DB::table('quote_comments')->count(),
    ]);

    expect($seen)->toBe([[$sent->id], 1, 0, 0])
        ->and(($this->system)(fn (): array => [DB::table('quote_versions')->count(), DB::table('quote_commercial_reviews')->count(), DB::table('quote_comments')->count()]))
        ->toBe([2, 2, 1]);
});

it('não mostra a outra empresa as cotações da empresa', function (): void {
    Q::send($this->scenario, Q::version($this->scenario, $this->quote));
    $other = $this->scenario->tenant->addBuyerUser($this->scenario->tenant->addBuyerCompany(['trade_name' => 'Construtora Vale Verde']), SystemRole::BuyerAdmin);

    expect($this->scenario->tenant->actingAs($other, static fn (): array => [
        DB::table('quotes')->count(), DB::table('quote_versions')->count(), DB::table('quote_items')->count(), DB::table('quote_requests')->count(), DB::table('quote_request_items')->count(),
    ]))->toBe([0, 0, 0, 0, 0]);
});

it('mantém o conteúdo da versão e dos itens imutável', function (): void {
    $version = Q::version($this->scenario, $this->quote);

    expect(fn () => ($this->system)(fn () => DB::table('quote_versions')->where('id', $version->id)->update(['total_cents' => 1])))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => ($this->system)(fn () => DB::table('quote_items')->where('quote_version_id', $version->id)->update(['discount_bps' => 9_000])))->toThrow(QueryException::class, 'permission denied')
        ->and(fn () => ($this->system)(fn () => DB::table('quote_requests')->where('quote_id', $this->quote->id)->delete()))->toThrow(QueryException::class, 'permission denied');
});

it('confere no COMMIT os itens com os totais da versão', function (): void {
    $version = Q::version($this->scenario, $this->quote);
    $item = (array) ($this->system)(fn () => DB::table('quote_items')->where('quote_version_id', $version->id)->first());
    $sku = $this->scenario->addSku();

    expect(fn () => ($this->system)(fn () => DB::table('quote_items')->insert([
        ...$item, 'id' => (string) Str::uuid7(), 'line_number' => 2, 'sku_id' => $sku->id,
        // A conferência é diferida: falha no COMMIT da transação.
    ])))->toThrow(PDOException::class, 'itens não conferem com os totais');
});

it('confere os valores de cada linha com o cálculo da aplicação', function (): void {
    $version = Q::version($this->scenario, $this->quote, ['discount_bps' => 1_250]);
    $item = (array) ($this->system)(fn () => DB::table('quote_items')->where('quote_version_id', $version->id)->first());

    expect($item['unit_net_price_cents'])->toBe(8_750)
        ->and(fn () => ($this->system)(fn () => DB::table('quote_items')->insert([
            ...$item, 'id' => (string) Str::uuid7(), 'quote_version_id' => Q::version($this->scenario, Q::quote($this->scenario, $this->buyer['membership']))->id,
            'unit_net_price_cents' => 8_700,
        ])))->toThrow(QueryException::class, 'quote_items_values_check');
});

it('aplica as transições da versão e da cotação', function (): void {
    $version = Q::send($this->scenario, Q::version($this->scenario, $this->quote));
    $update = fn (string $table, string $id, array $values) => ($this->system)(fn () => DB::table($table)->where('id', $id)->update($values));

    expect(fn () => $update('quote_versions', $version->id, ['status' => 'ready', 'sent_at' => null, 'version_number' => null]))->toThrow(QueryException::class, 'não permitida')
        ->and(fn () => $update('quote_versions', $version->id, ['version_number' => 7]))->toThrow(QueryException::class, 'uma única vez')
        ->and(fn () => $update('quotes', $this->quote->id, ['number' => 'COT-OUTRA']))->toThrow(QueryException::class, 'número definitivo');

    $update('quotes', $this->quote->id, ['status' => 'rejected', 'closed_at' => now(), 'close_reason_code' => 'price']);

    expect(fn () => $update('quotes', $this->quote->id, ['status' => 'proposed', 'closed_at' => null, 'close_reason_code' => null]))->toThrow(QueryException::class, 'não permitida')
        ->and(fn () => Q::version($this->scenario, $this->quote))->toThrow(QueryException::class, 'não recebe nova versão');
});

it('não envia ao comprador versão com violação sem aprovação comercial', function (): void {
    $version = Q::version($this->scenario, $this->quote, ['status' => QuoteVersionStatus::PendingApproval, 'requires_approval' => true, 'discount_bps' => 1_500]);
    $review = ($this->system)(fn () => DB::table('quote_commercial_reviews')->where('quote_version_id', $version->id)->value('id'));
    $decide = fn (array $values) => ($this->system)(fn () => DB::table('quote_commercial_reviews')->where('id', $review)->update($values));
    $staff = $this->scenario->tenant->ownerMembership;
    $approval = ['decision' => 'approved', 'decided_by_user_id' => $staff->user_id, 'decided_by_membership_id' => $staff->id, 'decided_at' => now()];

    // Pronta sem decisão: o envio é recusado.
    ($this->system)(fn () => DB::table('quote_versions')->where('id', $version->id)->update(['status' => 'ready']));

    expect(fn () => Q::send($this->scenario, $version))->toThrow(QueryException::class, 'envio exige avaliação comercial');

    $other = Q::version($this->scenario, Q::quote($this->scenario, $this->buyer['membership']), ['status' => QuoteVersionStatus::PendingApproval, 'requires_approval' => true, 'discount_bps' => 1_500]);
    $otherReview = ($this->system)(fn () => DB::table('quote_commercial_reviews')->where('quote_version_id', $other->id)->value('id'));
    $decideOther = fn (array $values) => ($this->system)(fn () => DB::table('quote_commercial_reviews')->where('id', $otherReview)->update($values));

    expect(fn () => $decideOther([...$approval, 'decider_limit_bps' => 1_000]))->toThrow(QueryException::class, 'quote_commercial_reviews_values_check')
        ->and(fn () => $decideOther(['decision' => 'rejected', 'decided_by_user_id' => $staff->user_id, 'decided_by_membership_id' => $staff->id, 'decider_limit_bps' => 0, 'decided_at' => now()]))
        ->toThrow(QueryException::class, 'quote_commercial_reviews_values_check');

    $decideOther([...$approval, 'decider_limit_bps' => 2_000, 'self_approved' => true]);

    expect(fn () => $decideOther(['decision' => 'rejected', 'decision_comment' => 'Mudei de ideia']))->toThrow(QueryException::class, 'uma única vez')
        ->and(fn () => $decide([...$approval, 'decider_limit_bps' => 2_000, 'self_approved' => true]))->toThrow(QueryException::class, 'só versão em aprovação');
});

it('permite uma versão em preparo, uma com o comprador e uma aceita por cotação', function (): void {
    Q::version($this->scenario, $this->quote);

    expect(fn () => Q::version($this->scenario, $this->quote))->toThrow(QueryException::class, 'quote_versions_one_in_preparation');
});

it('não aceita comentário de associação de outra empresa nem reserva sem titular', function (): void {
    $other = $this->scenario->tenant->addBuyerUser($this->scenario->tenant->addBuyerCompany(['trade_name' => 'Construtora Vale Verde']), SystemRole::BuyerAdmin);

    expect(fn () => ($this->system)(fn () => DB::table('quote_comments')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'buyer_company_id' => $this->company->id, 'quote_id' => $this->quote->id,
        'author_user_id' => $other->user_id, 'buyer_membership_id' => $other->id, 'author_name' => 'Outra empresa', 'visibility' => 'shared',
        'body' => 'Comentário cruzado', 'created_at' => now(),
    ])))->toThrow(QueryException::class, 'quote_comments_buyer_membership_id_company_fk')
        ->and(fn () => ($this->system)(fn () => DB::table('stock_reservations')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenantId, 'warehouse_id' => $this->scenario->warehouse->id, 'sku_id' => $this->scenario->sku->id,
            'quantity' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ])))->toThrow(QueryException::class, 'stock_reservations_holder_check');
});

it('não muda a origem de um pedido depois de gravado', function (): void {
    $order = Tests\Integration\Approvals\ApprovalFixtures::place($this->scenario, $this->buyer);

    expect(fn () => ($this->system)(fn () => DB::table('orders')->where('id', $order->id)->update(['source' => 'quote'])))->toThrow(QueryException::class, 'a origem não muda');
});
