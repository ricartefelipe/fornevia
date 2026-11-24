<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Observability\MetricsSnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Métricas Prometheus (RQ-121), só com o token de coleta
 * (Authorization: Bearer). Sem token configurado, o endpoint não existe.
 */
final class MetricsController
{
    public function __invoke(Request $request, MetricsSnapshot $snapshot): Response
    {
        $token = (string) config('observability.metrics_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        return response($snapshot->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
