<?php

declare(strict_types=1);

use App\Support\Observability\Metrics;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Illuminate\Database\QueryException;
use Tests\Support\Builders\TenantScenario;

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

    $pdo = new PDOException('deadlock detected');
    $pdo->errorInfo = ['40P01', 7, 'deadlock detected'];

    expect(fn () => app(TenantDatabase::class)->run(ExecutionContext::system($tenant->organization->id), static function () use ($pdo): void {
        throw new QueryException('pgsql', 'update stock_levels set reserved = 1', [], $pdo);
    }))->toThrow(QueryException::class);

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

it('não derruba a operação quando o Redis das métricas falha', function (): void {
    config(['database.redis.default.port' => 1]);
    app()->forgetInstance('redis');
    app()->forgetInstance(Metrics::class);

    $this->get('/health/live')->assertOk();
});
