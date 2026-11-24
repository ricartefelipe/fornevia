<?php

declare(strict_types=1);

use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Integrations\ApiClientsPage;
use App\Livewire\Staff\Integrations\CsvImportsPage;
use App\Livewire\Staff\Integrations\WebhooksPage;
use App\Models\Buyers\BuyerCompany;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use App\Models\Integrations\ApiClient;
use App\Models\Integrations\CsvImport;
use App\Models\Integrations\WebhookDelivery;
use App\Models\Integrations\WebhookEndpoint;
use Database\Factories\Support\BrazilianDocuments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\Builders\OrderingScenario;
use Tests\Support\Builders\TenantScenario;

/*
| Telas de integrações: clientes da API com token mostrado uma vez,
| endpoints de webhook com segredo, teste e entregas, e importação CSV
| com prévia, confirmação e histórico. Só com integrations.manage.
*/

beforeEach(function (): void {
    $this->withoutVite();
    config(['queue.default' => 'sync', 'integrations.webhooks.allow_private_networks' => false, 'integrations.webhooks.allow_http' => false]);
    $this->scenario = OrderingScenario::create(stock: '10', unitPriceCents: 10_000);
    $this->admin = $this->scenario->tenant->addStaff(SystemRole::Admin, User::factory()->withTwoFactor()->create());

    $this->page = function (string $url, ?Membership $membership = null): TestResponse {
        $membership ??= $this->admin;
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))->withSession(TenantScenario::sessionFor($membership))->get($url);
    };
    $this->as = function (Closure $callback, ?Membership $membership = null): mixed {
        $membership ??= $this->admin;
        $user = User::query()->findOrFail($membership->user_id);

        return $this->scenario->tenant->actingAs($membership, static function () use ($user, $callback): mixed {
            Livewire::actingAs($user);

            return $callback();
        });
    };
});

it('abre as três seções só para quem gerencia integrações', function (): void {
    foreach (['integrations.index' => 'Novo cliente da API', 'integrations.webhooks' => 'Novo endpoint', 'integrations.imports' => 'Nova importação'] as $route => $text) {
        ($this->page)(route($route))->assertOk()->assertSeeText($text);
        ($this->page)(route($route), $this->scenario->tenant->addStaff(SystemRole::SalesManager))->assertForbidden();
    }
});

it('cria cliente da API mostrando o token uma única vez e revoga', function (): void {
    $component = ($this->as)(fn () => Livewire::test(ApiClientsPage::class)
        ->set('name', 'ERP Protheus')
        ->set('permissions', ['orders.view', 'catalog.view'])
        ->set('validDays', 30)
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('copie agora')
        ->assertSee('ERP Protheus'));
    $token = $component->get('issuedToken');
    $client = $this->scenario->system(fn () => ApiClient::query()->sole());

    expect($token)->toMatch('/^\d+\|fvk_/')->and($client->permissions)->toBe(['orders.view', 'catalog.view']);
    $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token])->assertOk();

    ($this->as)(fn () => Livewire::test(ApiClientsPage::class)
        ->assertDontSee($token)
        ->call('revoke', $client->id)
        ->assertSee('Revogado'));
    app()->forgetScopedInstances();
    $this->getJson('/api/v1/orders', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
});

it('aponta os erros do formulário do cliente da API', function (): void {
    ($this->as)(fn () => Livewire::test(ApiClientsPage::class)
        ->set('name', '')->set('permissions', ['orders.view'])->call('create')->assertHasErrors('name')
        ->set('name', 'BI')->set('permissions', ['members.manage'])->call('create')->assertHasErrors('permissions')
        ->set('permissions', ['orders.view'])->set('validDays', 400)->call('create')->assertHasErrors('validDays')
        ->assertSet('issuedToken', null));
});

it('cadastra endpoint de webhook, envia teste e mostra as entregas', function (): void {
    Http::fake(['93.184.216.34/*' => Http::response('', 204)]);

    $component = ($this->as)(fn () => Livewire::test(WebhooksPage::class)
        ->set('url', 'https://10.0.0.5/hook')->set('events', ['order.confirmed'])->call('create')->assertHasErrors('url')
        ->set('url', 'https://93.184.216.34/hook')->set('events', [])->call('create')->assertHasErrors('events')
        ->set('events', ['order.confirmed', 'invoice.issued'])->call('create')->assertHasNoErrors()->assertSee('whsec_'));
    $endpoint = $this->scenario->system(fn () => WebhookEndpoint::query()->sole());

    ($this->as)(fn () => Livewire::test(WebhooksPage::class)->call('ping', $endpoint->id)->assertSee('Envio de teste agendado'));
    ($this->as)(fn () => Livewire::test(WebhooksPage::class)->assertSee('ping')->assertSee('Entregue')->assertSee('204')
        ->call('toggle', $endpoint->id)->assertSee('Endpoint desativado.')->assertSee('Desativado pela equipe.')
        ->call('toggle', $endpoint->id)->assertSee('Endpoint ativado.')
        ->call('rotate', $endpoint->id)->assertSee('whsec_'));

    expect($component->get('issuedSecret'))->toStartWith('whsec_')
        ->and($this->scenario->system(fn () => WebhookDelivery::query()->sole()->status))->toBe('delivered');

    ($this->as)(fn () => Livewire::test(WebhooksPage::class)->call('delete', $endpoint->id)->assertSee('Nenhum endpoint'));
});

it('importa pelo envio do arquivo com prévia, confirmação e histórico', function (): void {
    $csv = "razao_social;nome_fantasia;cnpj;email;telefone\nAlfa Indústria Ltda;Alfa;".BrazilianDocuments::cnpj().";;\nDelta Ltda;Delta;123;;\n";

    $component = ($this->as)(fn () => Livewire::test(CsvImportsPage::class)
        ->set('kind', 'customers')
        ->set('file', UploadedFile::fake()->createWithContent('clientes.csv', $csv))
        ->call('preview')
        ->assertHasNoErrors()
        ->assertSee('2 linha(s): 1 válida(s), 1 inválida(s)')
        ->assertSee('CNPJ inválido.'));
    // As linhas com problema vêm primeiro.
    $html = $component->html();
    expect(strpos($html, 'Delta Ltda'))->toBeLessThan(strpos($html, 'Alfa Indústria Ltda'));

    // A aplicação roda depois do commit da requisição: o histórico mostra a
    // fila e, no carregamento seguinte, o resultado.
    ($this->as)(fn () => $component->call('confirm')->assertSee('Importação confirmada')->assertSee('Na fila'));
    $component = ($this->as)(fn () => Livewire::test(CsvImportsPage::class)->assertSee('Concluída')->assertSee('1 de 2 aplicada(s)'));

    $import = $this->scenario->system(fn () => CsvImport::query()->sole());
    expect($import->status)->toBe('done')
        ->and($this->scenario->system(fn () => BuyerCompany::query()->where('legal_name', 'Alfa Indústria Ltda')->exists()))->toBeTrue();

    ($this->as)(fn () => $component->call('open', $import->id)->assertSee('Aplicada')->assertDontSee('Confirmar importação'));
});

it('recusa arquivo inválido e importação tudo ou nada com linha inválida', function (): void {
    ($this->as)(fn () => Livewire::test(CsvImportsPage::class)
        ->set('file', UploadedFile::fake()->createWithContent('x.csv', "nome;cnpj\nA;1\n"))
        ->call('preview')
        ->assertHasErrors('file')
        ->assertSee('Colunas obrigatórias ausentes')
        ->set('policy', 'all_or_none')
        ->set('file', UploadedFile::fake()->createWithContent('y.csv', "razao_social;nome_fantasia;cnpj;email;telefone\nDelta Ltda;Delta;123;;\n"))
        ->call('preview')
        ->call('confirm')
        ->assertSee('Importação recusada')
        ->assertSee('Recusada'));
});

it('não aceita envio temporário de quem não gerencia integrações', function (): void {
    $viewer = $this->scenario->tenant->addStaff(SystemRole::Viewer);

    ($this->as)(fn () => Livewire::test(CsvImportsPage::class)->call('_startUpload', 'file', [['name' => 'a.csv', 'size' => 10, 'type' => 'text/csv']], false)->assertForbidden(), $viewer);
});
