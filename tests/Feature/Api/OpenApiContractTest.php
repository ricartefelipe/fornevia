<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
| Contrato da API (docs/openapi.json): toda rota da API v1 está documentada
| e todo caminho documentado existe, com os mesmos métodos.
*/

it('documenta exatamente as rotas da API v1', function (): void {
    /** @var array{paths: array<string, array<string, mixed>>} $spec */
    $spec = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);
    $documented = [];

    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' '.$path;
        }
    }

    $routes = collect(Router::getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(static fn (Route $route): array => array_map(
            static fn (string $method): string => $method.' '.substr('/'.$route->uri(), strlen('/api/v1')),
            array_values(array_diff($route->methods(), ['HEAD'])),
        ))
        ->sort()->values()->all();

    sort($documented);

    expect($routes)->toBe($documented);
});

it('descreve o formato de erro e a autenticação', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($spec['openapi'])->toStartWith('3.1')
        ->and($spec['components']['securitySchemes']['bearer']['scheme'])->toBe('bearer')
        ->and($spec['components']['schemas']['Error']['required'])->toBe(['error', 'request_id'])
        ->and($spec['components']['parameters']['PerPage']['schema']['maximum'])->toBe(App\Actions\Api\ApiListing::MAX_PER_PAGE)
        ->and(collect($spec['paths']['/orders']['get']['parameters'])->firstWhere('name', 'commercial_status')['schema']['enum'])
        ->toBe(array_map(static fn (App\Enums\Ordering\CommercialStatus $s): string => $s->value, App\Enums\Ordering\CommercialStatus::cases()));
});
