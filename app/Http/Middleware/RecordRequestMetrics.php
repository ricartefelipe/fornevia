<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Observability\Metrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conta cada requisição por tipo (web, livewire, api, webhooks, health) e
 * classe de status, com a duração no histograma (RQ-121). O próprio
 * /metrics não entra na conta.
 */
final readonly class RecordRequestMetrics
{
    public function __construct(private Metrics $metrics) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);
        $response = $next($request);

        if (! $request->is('metrics')) {
            $this->metrics->observeRequest(self::kind($request), $response->getStatusCode(), (hrtime(true) - $started) / 1e9);
        }

        return $response;
    }

    private static function kind(Request $request): string
    {
        return match (true) {
            $request->is('api/*') => 'api',
            $request->is('livewire*') => 'livewire',
            $request->is('webhooks/*') => 'webhooks',
            $request->is('health/*') => 'health',
            default => 'web',
        };
    }
}
