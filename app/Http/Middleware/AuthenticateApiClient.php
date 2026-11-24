<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Integrations\ApiClientAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticação da API v1 (RF-110): token do Sanctum do cliente de API no
 * cabeçalho Authorization (Bearer), com o tenant do token. A requisição
 * inteira roda no contexto de integração desse tenant, com as permissões
 * do cliente limitadas às abilities do token.
 */
final readonly class AuthenticateApiClient
{
    public function __construct(private ApiClientAuthenticator $authenticator) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->authenticator->run((string) $request->bearerToken(), static fn (): Response => $next($request));
    }
}
