<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\Integrations\ApiClient;
use App\Support\Auth\Access;
use App\Support\Clock\Clock;
use App\Support\Integrations\ApiAbilities;
use App\Support\Tenancy\ExecutionContext;
use App\Support\Tenancy\TenantDatabase;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Confere o token ("id|segredo") pela função api_token_find (a busca
 * acontece antes de haver contexto), em tempo constante, com prazo e
 * cliente ativo; depois executa a requisição no contexto de integração do
 * tenant do token, com as permissões do cliente ∩ abilities do token e
 * limite de requisições por cliente.
 */
final readonly class ApiClientAuthenticator
{
    public const int PER_MINUTE = 300;

    public function __construct(
        private TenantDatabase $db,
        private Access $access,
        private Clock $clock,
    ) {}

    /**
     * @param  Closure(): Response  $next
     */
    public function run(string $bearer, Closure $next): Response
    {
        [$id, $secret] = array_pad(explode('|', $bearer, 2), 2, '');

        if (! ctype_digit($id) || strlen($id) > 18 || $secret === '') {
            throw new AuthenticationException;
        }

        $token = DB::selectOne('select * from api_token_find(?)', [(int) $id]);
        $now = $this->clock->now();

        if ($token === null || ! hash_equals((string) $token->token, hash('sha256', $secret))
            || ($token->expires_at !== null && $now->greaterThanOrEqualTo((string) $token->expires_at))) {
            throw new AuthenticationException;
        }

        $tenantId = (string) $token->tenant_id;
        $clientId = (string) $token->tokenable_id;
        /** @var list<string> $abilities */
        $abilities = array_values((array) json_decode((string) ($token->abilities ?? '[]'), true));

        if (RateLimiter::tooManyAttempts('api:'.$clientId, self::PER_MINUTE)) {
            throw new ThrottleRequestsException('Limite de requisições excedido.', null, ['Retry-After' => (string) RateLimiter::availableIn('api:'.$clientId)]);
        }

        RateLimiter::hit('api:'.$clientId, 60);

        return $this->db->run(ExecutionContext::integration($tenantId, $clientId), function () use ($clientId, $abilities, $id, $now, $tenantId, $next): Response {
            $client = ApiClient::query()->whereNull('revoked_at')->find($clientId) ?? throw new AuthenticationException;
            $granted = ApiAbilities::filter($client->permissions);
            $permissions = in_array('*', $abilities, true) ? $granted : array_values(array_intersect($granted, $abilities));

            $this->access->bindIntegration($client->id, $permissions);
            Context::add('tenant_id', $tenantId);
            Context::add('api_client_id', $client->id);
            DB::table('personal_access_tokens')->where('id', (int) $id)->update(['last_used_at' => $now]);
            $client->forceFill(['last_used_at' => $now])->save();

            return $next();
        });
    }
}
