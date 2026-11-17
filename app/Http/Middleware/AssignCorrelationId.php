<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identificador de correlação da requisição.
 *
 * Aceita X-Request-Id do chamador apenas em formato restrito; caso
 * contrário gera um novo. O valor entra no Context do Laravel e, por
 * isso, acompanha os logs e os jobs despachados durante a requisição.
 */
final class AssignCorrelationId
{
    public const string HEADER = 'X-Request-Id';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);
        $correlationId = is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,128}$/D', $incoming) === 1
            ? $incoming
            : (string) Str::uuid7();

        Context::add('correlation_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }
}
