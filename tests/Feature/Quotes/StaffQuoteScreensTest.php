<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Enums\Quotes\QuoteStatus;
use App\Enums\Quotes\QuoteVersionStatus;
use App\Livewire\Staff\Quotes\QuoteShow;
use App\Livewire\Staff\Quotes\QuoteVersionForm;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Integration\Quotes\QuoteFlow as F;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de cotação da equipe: filas, editor de versão com prévia no
| servidor, aprovação comercial pelo detalhe e dados internos só com
| permissão.
*/

beforeEach(function (): void {
    $this->withoutVite();
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->buyer = $this->scenario->buyerWithCart(quantity: '3', sameCompany: true);
    $this->quote = F::request($this->scenario, $this->buyer);
    $this->rep = $this->scenario->tenant->addStaff(SystemRole::SalesRep);
    $this->manager = $this->scenario->tenant->addStaff(SystemRole::SalesManager);
    $this->scenario->system(fn () => DB::table('buyer_companies')->where('id', $this->buyer['company']->id)->update(['sales_rep_membership_id' => $this->rep->id]));
    $this->scenario->system(fn () => DB::table('sku_costs')->insert([
        'id' => (string) Illuminate\Support\Str::uuid7(), 'tenant_id' => $this->scenario->tenant->organization->id, 'sku_id' => $this->scenario->sku->id,
        'cost_cents' => 7_000, 'effective_from' => now()->subDay(), 'created_at' => now(),
    ]));

    $this->page = function (string $url, Membership $membership): TestResponse {
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get($url);
    };
    $this->as = function (Membership $membership, Closure $callback): mixed {
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
});

it('mostra as filas e a carteira do representante', function (): void {
    ($this->page)(route('quotes.index'), $this->rep)->assertOk()->assertSeeText($this->quote->number);

    $other = $this->scenario->tenant->addStaff(SystemRole::SalesRep);

    ($this->page)(route('quotes.index'), $other)->assertOk()->assertDontSeeText($this->quote->number);
    ($this->page)(route('quotes.show', $this->quote->id), $other)->assertNotFound();
    ($this->page)(route('quotes.index', ['fila' => 'x', 'page' => 'y']), $this->manager)->assertOk();
});

it('elabora a versão com prévia do servidor e envia ao cliente', function (): void {
    ($this->as)($this->manager, fn () => Livewire::test(QuoteVersionForm::class, ['quote' => $this->quote->id])
        ->assertSet('lines.0.quantity', '3')
        ->set('lines.0.discount', '5')
        ->assertSee('R$ 95,00')
        ->assertSee('Total: R$ 285,00')
        ->assertSee('Enviar ao cliente')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('quotes.show', $this->quote->id)));

    expect(F::fresh($this->scenario, $this->quote)->status)->toBe(QuoteStatus::Proposed);
});

it('leva a versão do representante à aprovação e aprova pelo detalhe', function (): void {
    ($this->as)($this->rep, fn () => Livewire::test(QuoteVersionForm::class, ['quote' => $this->quote->id])
        ->set('lines.0.discount', '12')
        ->assertSee('Exigirá aprovação comercial')
        ->assertSee('Enviar para aprovação comercial')
        ->call('submit')
        ->assertHasErrors(['justification'])
        ->set('justification', 'Concorrente cobriu o preço')
        ->call('submit')
        ->assertRedirect());

    ($this->page)(route('quotes.approvals'), $this->manager)->assertOk()->assertSeeText($this->quote->number)->assertSeeText('Desconto acima do limite do perfil');

    ($this->as)($this->manager, fn () => Livewire::test(QuoteShow::class, ['quote' => $this->quote->id])
        ->assertSee('Aprovar exceção')
        ->assertSee('Custo')
        ->call('decide', true)
        ->assertSee('Exceção aprovada e proposta enviada ao comprador.'));

    $version = F::fresh($this->scenario, $this->quote)->versions->first();
    expect($version->status)->toBe(QuoteVersionStatus::Sent);
});

it('não mostra custo nem margem sem permissão de ver custos', function (): void {
    F::submit($this->scenario, $this->rep, $this->quote, discount: '2');

    ($this->as)($this->rep, fn () => Livewire::test(QuoteShow::class, ['quote' => $this->quote->id])
        ->assertDontSee('R$ 70,00')
        ->assertDontSee('Margem')
        ->assertSee('Dentro da política'));
});
