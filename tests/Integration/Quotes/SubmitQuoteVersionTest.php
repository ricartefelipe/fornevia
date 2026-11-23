<?php

declare(strict_types=1);

use App\Actions\Quotes\Staff\SendQuoteVersion;
use App\Actions\Quotes\Staff\WithdrawQuoteVersion;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Ordering\Exceptions\PricesChanged;
use App\Domain\Quotes\Exceptions\CommercialApprovalNotAllowed;
use App\Domain\Quotes\Exceptions\CommercialApprovalRequired;
use App\Domain\Quotes\Exceptions\InvalidQuote;
use App\Domain\Quotes\Exceptions\QuoteVersionOutdated;
use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Enums\Quotes\StockHoldEndReason;
use App\Enums\Quotes\StockHoldStatus;
use App\Models\Quotes\QuoteVersion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;

/*
| Proposta da equipe (RF-040): preço do servidor, avaliação comercial
| (RN-041, RN-032, RN-033), envio com numeração, substituição, reserva com
| prazo tudo ou nada (RN-043) e retirada.
*/

beforeEach(function (): void {
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->quote = F::request($this->scenario, $this->buyer);
    $this->rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->reserved = fn (): string => (string) $this->scenario->system(fn () => DB::table('stock_levels')->where('sku_id', $this->scenario->sku->id)->value('reserved'));
});

it('envia direto a proposta dentro da política, com o preço do servidor', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote, discount: '5');
    $quote = F::fresh($this->scenario, $this->quote);
    $stored = $quote->versions->first();

    expect($version->status)->toBe(QuoteVersionStatus::Sent)
        ->and($quote->status)->toBe(QuoteStatus::Proposed)
        ->and($stored->version_number)->toBe(1)
        ->and($stored->total_cents->cents)->toBe(28_500)
        ->and($stored->items[0]->unit_price_cents->cents)->toBe(10_000)
        ->and($stored->items[0]->unit_net_price_cents->cents)->toBe(9_500)
        ->and($stored->review->requires_approval)->toBeFalse()
        ->and($stored->stock_hold_status)->toBe(StockHoldStatus::None);
});

it('exige justificativa e aprovação comercial para desconto acima do limite do autor', function (): void {
    $this->rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $this->scenario->system(fn () => DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => $this->rep->id]));

    expect(fn () => F::submit($this->scenario, $this->rep, $this->quote, discount: '10'))->toThrow(CommercialApprovalRequired::class, 'justifique')
        ->and(fn () => F::submit($this->scenario, $this->rep, $this->quote, discount: '10', overrides: ['justification' => 'Concorrente cobriu o preço', 'selfApprove' => true]))
        ->toThrow(CommercialApprovalNotAllowed::class);

    $version = F::submit($this->scenario, $this->rep, $this->quote, discount: '10', overrides: ['justification' => 'Concorrente cobriu o preço']);
    $quote = F::fresh($this->scenario, $this->quote);

    expect($version->status)->toBe(QuoteVersionStatus::PendingApproval)
        ->and($quote->status)->toBe(QuoteStatus::Requested)
        ->and($quote->versions->first()->review->violations)->toBe(['exceeds_role_limit'])
        ->and($quote->versions->first()->review->max_discount_bps)->toBe(1_000)
        ->and($this->scenario->tenant->actingAs($this->buyer['membership'], static fn (): int => QuoteVersion::query()->count()))->toBe(0);
});

it('permite a autoaprovação justificada de quem tem limite e a registra', function (): void {
    $this->scenario->system(fn () => DB::table('sku_commercial_policies')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $this->scenario->tenant->organization->id, 'sku_id' => $this->scenario->sku->id,
        'min_unit_price_cents' => 9_500, 'created_at' => now(), 'updated_at' => now(),
    ]));

    $version = F::submit($this->scenario, $this->manager, $this->quote, discount: '10', overrides: ['justification' => 'Lote fechado para a obra', 'selfApprove' => true]);
    $review = F::fresh($this->scenario, $this->quote)->versions->first()->review;

    expect($version->status)->toBe(QuoteVersionStatus::Sent)
        ->and($review->violations)->toBe(['below_minimum_price'])
        ->and($review->self_approved)->toBeTrue()
        ->and($review->decision)->toBe('approved');
});

it('recusa SKU sem preço, total diferente da prévia e estado desatualizado', function (): void {
    $this->scenario->system(fn () => DB::table('price_list_items')->where('sku_id', $this->scenario->sku->id)->delete());
    $this->scenario->system(static fn () => app(App\Services\Pricing\PriceVersion::class)->bump());

    expect(fn () => F::submit($this->scenario, $this->manager, $this->quote))->toThrow(InvalidQuote::class, 'cadastre o preço');

    $restored = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $buyer = $restored->buyerWithCart(quantity: '3');
    $quote = F::request($restored, $buyer);
    $manager = $restored->tenant->addStaff(SystemRole::SalesManager);
    $stale = F::state($restored, $quote);
    F::submit($restored, $manager, $quote);

    expect(fn () => F::submit($restored, $manager, $quote, overrides: ['expectedTotalCents' => 1]))->toThrow(PricesChanged::class)
        ->and(fn () => F::submit($restored, $manager, $quote, overrides: ['reviewed' => $stale]))->toThrow(QuoteVersionOutdated::class);
});

it('substitui a proposta enviada pela nova versão, com número seguinte', function (): void {
    $first = F::submit($this->scenario, $this->manager, $this->quote);
    $second = F::submit($this->scenario, $this->manager, $this->quote, discount: '3');
    $versions = F::fresh($this->scenario, $this->quote)->versions->keyBy('id');

    expect($versions[$first->id]->status)->toBe(QuoteVersionStatus::Superseded)
        ->and($versions[$second->id]->status)->toBe(QuoteVersionStatus::Sent)
        ->and($versions[$second->id]->version_number)->toBe(2);
});

it('reserva o estoque até o prazo, tudo ou nada, e libera ao substituir', function (): void {
    $hold = now((string) config('app.business_timezone'))->addDays(3)->toDateString();
    $first = F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => $hold]);

    expect(($this->reserved)())->toBe('3.000')
        ->and(F::fresh($this->scenario, $this->quote)->versions->first()->stock_hold_status)->toBe(StockHoldStatus::Active);

    expect(fn () => F::submit($this->scenario, $this->manager, $this->quote, quantity: '11', overrides: ['stockHoldUntilDay' => $hold]))->toThrow(InsufficientStock::class)
        ->and(($this->reserved)())->toBe('3.000');

    F::submit($this->scenario, $this->manager, $this->quote, quantity: '5', overrides: ['stockHoldUntilDay' => $hold]);
    $old = F::fresh($this->scenario, $this->quote)->versions->firstWhere('id', $first->id);

    expect(($this->reserved)())->toBe('5.000')
        ->and($old->stock_hold_status)->toBe(StockHoldStatus::Ended)
        ->and($old->stock_hold_end_reason)->toBe(StockHoldEndReason::Superseded);
});

it('retira a proposta enviada, encerra a reserva e volta a aguardar proposta', function (): void {
    $version = F::submit($this->scenario, $this->manager, $this->quote, overrides: ['stockHoldUntilDay' => now((string) config('app.business_timezone'))->addDay()->toDateString()]);

    $state = F::state($this->scenario, $this->quote);

    expect(fn () => $this->scenario->tenant->actingAs($this->manager, fn () => app(WithdrawQuoteVersion::class)->handle($this->quote->id, $state, $version->id, 'ok')))
        ->toThrow(InvalidQuote::class, 'motivo');

    $this->scenario->tenant->actingAs($this->manager, fn () => app(WithdrawQuoteVersion::class)->handle($this->quote->id, $state, $version->id, 'Preço errado na tabela'));
    $quote = F::fresh($this->scenario, $this->quote);

    expect($quote->status)->toBe(QuoteStatus::Requested)
        ->and($quote->versions->first()->status)->toBe(QuoteVersionStatus::Withdrawn)
        ->and($quote->versions->first()->stock_hold_end_reason)->toBe(StockHoldEndReason::Withdrawn)
        ->and(($this->reserved)())->toBe('0.000');
});

it('envia depois a versão aprovada e restringe o representante à carteira', function (): void {
    $other = $this->scenario->tenant->addStaff(SystemRole::SalesRep);

    expect(fn () => F::submit($this->scenario, $other, $this->quote))->toThrow(ModelNotFoundException::class);

    $this->scenario->system(fn () => DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => $this->rep->id]));
    $version = F::submit($this->scenario, $this->rep, $this->quote, discount: '10', overrides: ['justification' => 'Concorrente cobriu o preço']);

    // Versão em aprovação comercial não está pronta para envio.
    $state = F::state($this->scenario, $this->quote);

    expect(fn () => $this->scenario->tenant->actingAs($this->rep, fn () => app(SendQuoteVersion::class)->handle($this->quote->id, $state, $version->id)))
        ->toThrow(QuoteVersionOutdated::class);
});

it('devolve o envio concluído na repetição com a mesma chave, mesmo que a condição tenha deixado de valer', function (): void {
    $overrides = ['idempotencyKey' => (string) Illuminate\Support\Str::uuid7(), 'reviewed' => F::state($this->scenario, $this->quote)];
    $first = F::submit($this->scenario, $this->manager, $this->quote, overrides: $overrides);
    $this->scenario->system(fn () => DB::table('buyer_payment_terms')->where('buyer_company_id', $this->buyer['company']->id)->delete());

    expect(F::submit($this->scenario, $this->manager, $this->quote, overrides: $overrides)->id)->toBe($first->id)
        ->and(fn () => F::submit($this->scenario, $this->manager, $this->quote))->toThrow(InvalidQuote::class, 'Condição de pagamento');
});
