<?php

declare(strict_types=1);

use App\Support\Observability\Metrics;
use Illuminate\Database\QueryException;
use Tests\Support\Builders\TenantScenario;

function conflictPdo(string $state): PDOException
{
    $pdo = new PDOException('conflito de concorrência');
    $pdo->errorInfo = [$state, 7, 'conflito de concorrência'];

    return $pdo;
}

/*
| Métricas Prometheus (RQ-121): token de coleta, requisições por tipo e
| status com histograma de duração, filas, outbox, jobs com falha e
| conflitos de concorrência no banco.
*/

beforeEach(function (): void {
    config(['observability.metrics_token' => 'token-de-coleta-123']);
    app(Metrics::class)->reset();
    $this->scrape = fn (?string $token = 'token-de-coleta-123') => $this->withHeaders($token !== null ? ['Authorization' => 'Bearer '.$token] : [])->get('/metrics');
});

it('só existe com token configurado e exige o token', function (): void {
    ($this->scrape)(null)->assertUnauthorized();
    ($this->scrape)('outro')->assertUnauthorized();

    config(['observability.metrics_token' => null]);
    ($this->scrape)()->assertNotFound();
});

it('conta requisições por tipo e classe de status, com histograma de duração', function (): void {
    $this->get('/health/live')->assertOk();
    $this->get('/health/live')->assertOk();
    $this->getJson('/api/v1/orders')->assertUnauthorized();

    $body = ($this->scrape)()->assertOk()->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();

    expect($body)->toContain("# TYPE fornevia_http_requests_total counter\n")
        ->toContain('fornevia_http_requests_total{kind="health",status="2xx"} 2')
        ->toContain('fornevia_http_requests_total{kind="api",status="4xx"} 1')
        ->toContain("# TYPE fornevia_http_request_duration_seconds histogram\n")
        ->toContain('fornevia_http_request_duration_seconds_bucket{kind="health",le="+Inf"} 2')
        ->toContain('fornevia_http_request_duration_seconds_count{kind="health"} 2')
        ->toMatch('/fornevia_http_request_duration_seconds_sum\{kind="health"\} [0-9.]+/')
        // A própria coleta não entra na conta.
        ->not->toContain('kind="web"');
});

it('expõe filas, outbox, jobs com falha e conflitos de concorrência', function (): void {
    $tenant = TenantScenario::create();
    config(['queue.default' => 'sync']);

    try {
        dispatch(static fn () => throw new RuntimeException('falha de teste'));
    } catch (RuntimeException) {
        // O job síncrono propaga a falha depois de registrá-la.
    }

    // Conflito em requisição: a exceção vira resposta 500 e é contada ao ser reportada.
    Illuminate\Support\Facades\Route::middleware('web')->get('/teste/conflito', static function (): never {
        throw new QueryException('pgsql', 'update stock_levels set reserved = 1', [], conflictPdo('40P01'));
    });
    $this->get('/teste/conflito')->assertStatus(500);

    $body = ($this->scrape)()->assertOk()->getContent();

    // Na fila síncrona dos testes, o nome da fila é o da conexão.
    expect($body)->toContain('fornevia_jobs_failed_total{queue="sync"} 1')
        ->toContain('fornevia_db_conflicts_total{reason="deadlock"} 1')
        ->toContain('fornevia_queue_size{queue="default"}')
        ->toContain('fornevia_outbox_pending ')
        ->toContain('fornevia_outbox_oldest_pending_age_seconds ')
        ->toContain('fornevia_outbox_dead ')
        ->toContain('fornevia_failed_jobs ')
        ->toContain('fornevia_metrics_collection_errors 0');
});

it('conta conflitos de transações aninhadas e por motivo', function (): void {
    $query = new QueryException('pgsql', 'select 1', [], conflictPdo('40P01'));
    report(new Illuminate\Database\DeadlockException($query->getMessage(), 0, $query));
    report(new QueryException('pgsql', 'select 1', [], conflictPdo('40001')));
    report(new QueryException('pgsql', 'select 1', [], conflictPdo('55P03')));
    report(new QueryException('pgsql', 'select 1', [], conflictPdo('23505')));

    expect(($this->scrape)()->getContent())->toContain('fornevia_db_conflicts_total{reason="deadlock"} 1')
        ->toContain('fornevia_db_conflicts_total{reason="serialization"} 1')
        ->toContain('fornevia_db_conflicts_total{reason="lock_not_available"} 1')
        ->not->toContain('23505');
});

it('exporta todos os limites do histograma, inclusive os vazios', function (): void {
    app(Metrics::class)->observeRequest('webhooks', 200, 3.0);
    $body = ($this->scrape)()->getContent();

    foreach (['0.05' => 0, '0.1' => 0, '0.25' => 0, '0.5' => 0, '1' => 0, '2.5' => 0, '5' => 1, '10' => 1, '+Inf' => 1] as $le => $count) {
        expect($body)->toContain('fornevia_http_request_duration_seconds_bucket{kind="webhooks",le="'.$le.'"} '.$count."\n");
    }
});

it('conta as respostas da pilha global, como a manutenção', function (): void {
    app()->maintenanceMode()->activate([]);
    $this->get('/health/live')->assertStatus(503);
    app()->maintenanceMode()->deactivate();

    expect(($this->scrape)()->getContent())->toContain('fornevia_http_requests_total{kind="health",status="5xx"} 1');
});

it('não derruba a operação e suspende as gravações quando o Redis das métricas falha', function (): void {
    config(['database.redis.metrics.port' => 1]);
    app()->forgetInstance('redis');
    app()->forgetInstance(Metrics::class);
    Illuminate\Support\Facades\Log::spy();

    $this->get('/health/live')->assertOk();
    $this->get('/health/live')->assertOk();

    Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->once();
});

it('não expõe séries por distribuidor quando desligadas, nem as gravadas antes', function (): void {
    config(['observability.per_tenant' => true]);
    app(Metrics::class)->observeRequest('web', 200, 0.01, '0193a1f0-0000-7000-8000-000000000001');
    expect(($this->scrape)()->getContent())->toContain('by_tenant');

    config(['observability.per_tenant' => false]);
    app(Metrics::class)->observeRequest('web', 200, 0.01, '0193a1f0-0000-7000-8000-000000000002');

    expect(($this->scrape)()->getContent())->not->toContain('by_tenant')->not->toContain('0193a1f0');
});

it('mantém fixos os distribuidores com série própria, para os contadores nunca diminuírem', function (): void {
    config(['observability.per_tenant' => true, 'observability.tenant_series_limit' => 1]);
    $small = '0193a1f0-0000-7000-8000-000000000011';
    $big = '0193a1f0-0000-7000-8000-000000000012';
    app(Metrics::class)->observeRequest('web', 200, 0.01, $small);

    // Um distribuidor de volume maior chega depois: fica em "outros".
    for ($i = 0; $i < 5; $i++) {
        app(Metrics::class)->observeRequest('web', 200, 0.01, $big);
    }

    expect(($this->scrape)()->getContent())->toContain("fornevia_http_requests_by_tenant_total{tenant=\"{$small}\",status=\"2xx\"} 1")
        ->toContain('fornevia_http_requests_by_tenant_total{tenant="outros",status="2xx"} 5')
        ->not->toContain($big);
});

it('conta requisições por distribuidor e soma os de menor volume em outros', function (): void {
    config(['observability.per_tenant' => true, 'observability.tenant_series_limit' => 2]);
    $tenants = ['0193a1f0-0000-7000-8000-00000000000a' => 3, '0193a1f0-0000-7000-8000-00000000000b' => 2, '0193a1f0-0000-7000-8000-00000000000c' => 1, '0193a1f0-0000-7000-8000-00000000000d' => 1];

    foreach ($tenants as $tenant => $count) {
        for ($i = 0; $i < $count; $i++) {
            app(Metrics::class)->observeRequest('web', 200, 0.01, $tenant);
        }
    }
    app(Metrics::class)->observeRequest('web', 500, 0.01, '0193a1f0-0000-7000-8000-00000000000a');
    app(Metrics::class)->observeRequest('web', 200, 0.01, 'nao-e-uuid');

    $body = ($this->scrape)()->getContent();

    expect($body)->toContain('fornevia_http_requests_by_tenant_total{tenant="0193a1f0-0000-7000-8000-00000000000a",status="2xx"} 3')
        ->toContain('fornevia_http_requests_by_tenant_total{tenant="0193a1f0-0000-7000-8000-00000000000a",status="5xx"} 1')
        ->toContain('fornevia_http_requests_by_tenant_total{tenant="0193a1f0-0000-7000-8000-00000000000b",status="2xx"} 2')
        ->toContain('fornevia_http_requests_by_tenant_total{tenant="outros",status="2xx"} 2')
        ->not->toContain('00000000000c')
        ->not->toContain('nao-e-uuid');
});

it('identifica o distribuidor da requisição autenticada e a outbox pendente por distribuidor', function (): void {
    config(['observability.per_tenant' => true]);
    $scenario = Tests\Support\Builders\OrderingScenario::create();
    Tests\Integration\Approvals\ApprovalFixtures::place($scenario, $scenario->buyerWithCart(quantity: '1'));
    [, $token] = $scenario->tenant->actingAs($scenario->tenant->ownerMembership, static fn (): array => app(App\Actions\Integrations\ManageApiClients::class)->create('ERP', ['orders.view'], 30));
    app(Metrics::class)->reset();
    // O distribuidor precisa vir da própria requisição, não da preparação do teste.
    Illuminate\Support\Facades\Context::forgetHidden(App\Support\Tenancy\TenantDatabase::METRICS_TENANT_KEY);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/v1/orders')->assertOk();
    $tenant = $scenario->tenant->organization->id;
    $body = ($this->scrape)()->getContent();

    expect($body)->toContain("fornevia_http_requests_by_tenant_total{tenant=\"{$tenant}\",status=\"2xx\"} 1")
        ->toContain("fornevia_outbox_pending_by_tenant{tenant=\"{$tenant}\"}");
});
